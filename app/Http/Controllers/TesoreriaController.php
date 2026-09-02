<?php

namespace App\Http\Controllers;

use App\Exports\Tesoreria\MovimientosExport;
use App\Http\Requests\ReordenarCuentasRequest;
use App\Http\Requests\StoreTransferenciaRequest;
use App\Models\CuentaTesoreria;
use App\Services\Tesoreria\SeccionesMovimientos;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $cuentas = CuentaTesoreria::orderBy('tipo')->ordenadas()->get();

        return response()->json(['data' => $cuentas->groupBy('tipo')]);
    }

    /**
     * Reordena las cuentas de un bloque (spec 085, FR-006).
     *
     * La comparación de conjunto **es** el control de concurrencia (FR-008). No hay
     * versionado ni lock: el front manda la lista completa de ids del bloque tal como
     * la vio, así que si otra sesión creó o borró una cuenta de ese tipo mientras se
     * arrastraba, el conjunto recibido difiere del real y se rechaza entero con 409.
     * Un id de otro tipo cae por el mismo chequeo, que es lo que impide que un
     * reordenamiento cambie el `tipo` de una cuenta. Los tres casos —id ajeno, id
     * faltante, id sobrante— se cubren con la misma comparación.
     */
    public function reordenarCuentas(ReordenarCuentasRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $tipo = $datos['tipo'];
        $ids = array_map('intval', $datos['ids']);

        $esperados = CuentaTesoreria::porTipo($tipo)->pluck('id')->map('intval')->all();

        sort($esperados);
        $recibidos = $ids;
        sort($recibidos);

        if ($esperados !== $recibidos) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'El listado de cuentas cambió mientras reordenabas. Se actualizó la lista, volvé a intentarlo.',
            ], 409);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                CuentaTesoreria::whereKey($id)->update(['orden' => $i + 1]);
            }
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Orden actualizado con éxito.',
            'saldos' => $this->tesoreria->saldos(),
        ]);
    }

    /** Select2 con saldo por cuenta, para los selectores de transferencia (FR-017). */
    public function cuentasOpciones(Request $request): JsonResponse
    {
        $termino = $request->input('q');

        // Alfabético y no `ordenadas()`: ese scope respeta el `orden` manual, que se repite entre
        // tipos de cuenta (hay varios `1`, varios `2`...) y deja el desplegable de "Elija una
        // cuenta" con las cajas salteadas. Para buscar una cuenta entre 21, el alfabético es el
        // único orden predecible.
        $cuentas = CuentaTesoreria::visibles()
            ->when($termino, fn ($q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->orderBy('nombre')
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
        $cuentas = CuentaTesoreria::visibles()->ordenadas()->get(['id', 'nombre']);

        return view('tesoreria.movimientos', compact('CurrentPage', 'desde', 'hasta', 'cuentas'));
    }

    /** JSON del informe (totales + desglose por cuenta) para el rango elegido. */
    public function movimientosData(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $cuentasActivas = $this->cuentasActivas($request);

        return response()->json($this->tesoreria->flujo($desde, $hasta, $cuentasActivas));
    }

    /**
     * Secciones Cobros/Pagos del informe, como las muestra Contagram.
     *
     * El XLSX y el PDF salen de acá para que no se puedan desincronizar: las reglas de qué
     * cuentas lista cada sección viven en `SeccionesMovimientos`.
     *
     * @return array{cobros: list<array{nombre: string, monto: float}>, pagos: list<array{nombre: string, monto: float}>}
     */
    private function seccionesMovimientos(array $flujo): array
    {
        return (new SeccionesMovimientos)->armar($flujo, CuentaTesoreria::where('visible', true)->get());
    }

    /**
     * Informe de Movimientos como XLSX, calcado del que genera Contagram (FR-029).
     *
     * Antes devolvía un CSV con los mismos datos pero disposición propia. El nombre del archivo
     * sigue el patrón de Contagram —"Informe Final DD-MM-YYYY HHMM Hs.xlsx"—, igual que los tres
     * informes de la spec 067. Ver `App\Exports\Tesoreria\MovimientosExport`.
     */
    public function movimientosExport(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        [$desde, $hasta] = $this->rangoMovimientos($request);
        $flujo = $this->tesoreria->flujo($desde, $hasta, $this->cuentasActivas($request));

        return Excel::download(
            new MovimientosExport($flujo, $desde, $hasta, $this->seccionesMovimientos($flujo)),
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
            'secciones' => $this->seccionesMovimientos($flujo),
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
