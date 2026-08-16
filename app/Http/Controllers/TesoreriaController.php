<?php

namespace App\Http\Controllers;

use App\Exports\Tesoreria\MovimientosExport;
use App\Http\Requests\StoreTransferenciaRequest;
use App\Models\CuentaTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Vistas globales de Tesorería: Saldos (pestaña por defecto), Movimientos
 * (informe de flujo de caja), configuración de cuentas y transferencias. El
 * CRUD de la cuenta en sí y su ficha/ledger viven en CuentaTesoreriaController
 * (plan.md — Structure Decision).
 */
class TesoreriaController extends Controller
{
    public function __construct(private readonly Tesoreria $tesoreria)
    {
    }

    /** Pestaña Saldos (US1): A Cobrar / A Pagar / Disponible, a la fecha de corte (default hoy). */
    public function saldos(Request $request)
    {
        $CurrentPage = 'tesoreria';
        $fecha = $this->fechaCorte($request);
        $saldos = $this->tesoreria->saldos($fecha);

        return view('tesoreria.saldos', compact('CurrentPage', 'saldos', 'fecha'));
    }

    /** Refresco AJAX de los saldos al cambiar la fecha de corte. */
    public function saldosData(Request $request): JsonResponse
    {
        return response()->json($this->tesoreria->saldos($this->fechaCorte($request)));
    }

    private function fechaCorte(Request $request): Carbon
    {
        return $request->filled('fecha') ? Carbon::parse($request->input('fecha')) : Carbon::now()->local()->startOfDay();
    }

    /** Configuración de cuentas (icono de ajustes), agrupada por tipo (FR-009). */
    public function configCuentas(): JsonResponse
    {
        $cuentas = CuentaTesoreria::orderBy('tipo')->orderBy('orden')->orderBy('nombre')->get();

        return response()->json(['data' => $cuentas->groupBy('tipo')]);
    }

    /** Select2 con saldo por cuenta, para los selectores de transferencia (FR-017). */
    public function cuentasOpciones(Request $request): JsonResponse
    {
        $termino = $request->input('q');

        $cuentas = CuentaTesoreria::visibles()
            ->when($termino, fn ($q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('orden')->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $cuentas->map(fn (CuentaTesoreria $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'tipo' => $c->tipo,
                'saldo' => $c->saldoA(),
            ]),
        ]);
    }

    /** Movimiento entre Cuentas (US3): transferencia con partida doble (FR-015/FR-016). */
    public function transferir(StoreTransferenciaRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $salida = CuentaTesoreria::findOrFail($datos['cuenta_salida_id']);
        $entrada = CuentaTesoreria::findOrFail($datos['cuenta_entrada_id']);

        $this->tesoreria->transferir(
            $salida, $entrada, (float) $datos['monto'], Carbon::parse($datos['fecha']),
            $datos['observacion'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Movimiento creado con éxito.',
            'saldos' => $this->tesoreria->saldos(),
        ], 201);
    }

    /** Pestaña Movimientos (US5): informe consolidado de flujo de caja. */
    public function movimientos(Request $request)
    {
        $CurrentPage = 'tesoreria';
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $cuentas = CuentaTesoreria::visibles()->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']);

        return view('tesoreria.movimientos', compact('CurrentPage', 'desde', 'hasta', 'cuentas'));
    }

    /** JSON del informe (totales + desglose por cuenta) para el rango elegido. */
    public function movimientosData(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $cuentasActivas = $this->cuentasActivas($request);

        return response()->json($this->tesoreria->flujo($desde, $hasta, $cuentasActivas));
    }

    /** Export del informe Movimientos a planilla (FR-029). */
    /**
     * Informe de Movimientos como XLSX, calcado del que genera Contagram.
     *
     * Antes devolvía un CSV con los mismos datos pero disposición propia. El nombre del archivo
     * sigue el patrón de Contagram —"Informe Final DD-MM-YYYY HHMM Hs.xlsx"—, igual que los tres
     * informes de la spec 067. Ver `App\Exports\Tesoreria\MovimientosExport`.
     */
    public function movimientosExport(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $flujo = $this->tesoreria->flujo($desde, $hasta, $this->cuentasActivas($request));

        // Contagram lista TODAS las cuentas de cada sección, no sólo las que tuvieron movimiento
        // (las demás van en 0), así que el export necesita el catálogo además del flujo.
        $cuentas = CuentaTesoreria::where('visible', true)->get();

        return Excel::download(
            new MovimientosExport($flujo, $desde, $hasta, $cuentas),
            'Informe Final '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    /** Informe Movimientos como PDF inline (modal compartido — CLAUDE.md §4). */
    public function movimientosPdf(Request $request)
    {
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $flujo = $this->tesoreria->flujo($desde, $hasta, $this->cuentasActivas($request));

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tesoreria.pdf.movimientos', [
            'desde' => $desde, 'hasta' => $hasta, 'flujo' => $flujo,
        ]);

        return $pdf->stream('movimientos-tesoreria.pdf', ['Content-Disposition' => 'inline']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangoMovimientos(Request $request): array
    {
        $desde = $request->filled('desde') ? Carbon::parse($request->input('desde')) : Carbon::now()->local()->startOfDay()->startOfMonth();
        $hasta = $request->filled('hasta') ? Carbon::parse($request->input('hasta')) : Carbon::now()->local()->startOfDay();

        return [$desde, $hasta];
    }

    /** @return array<int>|null */
    private function cuentasActivas(Request $request): ?array
    {
        if (! $request->filled('cuentas_activas')) {
            return null;
        }

        return array_map('intval', (array) $request->input('cuentas_activas'));
    }
}
