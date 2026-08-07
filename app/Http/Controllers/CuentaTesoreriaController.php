<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCuentaTesoreriaRequest;
use App\Http\Requests\UpdateCuentaTesoreriaRequest;
use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

/**
 * CRUD de la cuenta de tesorería (US2) y su ficha/ledger (US4). Toda la
 * lógica de dinero (saldo inicial, partida doble) vive en Services\Tesoreria\
 * Tesoreria — este controlador sólo orquesta HTTP (plan.md).
 */
class CuentaTesoreriaController extends Controller
{
    /** Operaciones editables/borrables íntegramente desde acá (research §7-8). */
    private const TIPOS_NATIVOS = ['saldo_inicial', 'movimiento_entre_cuentas'];

    public function __construct(private readonly Tesoreria $tesoreria)
    {
    }

    /** Alta de cuenta (FR-001/FR-002). */
    public function store(StoreCuentaTesoreriaRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $saldoInicial = (float) ($datos['saldo_inicial'] ?? 0);
        $fecha = Carbon::parse($datos['saldo_inicial_fecha']);

        $cuenta = DB::transaction(function () use ($datos, $saldoInicial, $fecha) {
            $cuenta = CuentaTesoreria::create([
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'visible' => true,
                'es_sistema' => false,
                'saldo_inicial' => $saldoInicial,
                'saldo_inicial_fecha' => $fecha,
            ]);

            if ($saldoInicial != 0) {
                $this->tesoreria->registrarSaldoInicial($cuenta, $saldoInicial, $fecha);
            }

            return $cuenta;
        });

        return response()->json(['ok' => true, 'mensaje' => 'Cuenta creada.', 'cuenta' => $cuenta], 201);
    }

    /** Edición (FR-003/FR-004: sin `tipo`; FR-006 vía UpdateCuentaTesoreriaRequest). */
    public function update(UpdateCuentaTesoreriaRequest $request, CuentaTesoreria $cuenta): JsonResponse
    {
        $datos = $request->validated();

        $cuenta->update([
            'nombre' => $datos['nombre'],
            'saldo_inicial' => (float) ($datos['saldo_inicial'] ?? 0),
            'saldo_inicial_fecha' => Carbon::parse($datos['saldo_inicial_fecha']),
            'visible' => $request->boolean('visible', $cuenta->visible),
        ]);

        // Mantiene el movimiento "Saldo Inicial" en línea con los datos editados.
        $movimientoInicial = $cuenta->movimientos()->where('tipo', 'saldo_inicial')->first();
        if ($movimientoInicial) {
            $movimientoInicial->update([
                'monto' => $cuenta->saldo_inicial,
                'fecha' => $cuenta->saldo_inicial_fecha,
            ]);
        } elseif ((float) $cuenta->saldo_inicial != 0) {
            $this->tesoreria->registrarSaldoInicial($cuenta, (float) $cuenta->saldo_inicial, $cuenta->saldo_inicial_fecha);
        }

        return response()->json(['ok' => true, 'mensaje' => 'Cuenta actualizada.', 'cuenta' => $cuenta->fresh()]);
    }

    /** Baja física, bloqueada si tiene operaciones o es del sistema (FR-006/FR-007/FR-008). */
    public function destroy(CuentaTesoreria $cuenta): JsonResponse
    {
        if ($cuenta->es_sistema) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'La cuenta es del sistema y no puede eliminarse.',
            ], 422);
        }

        if ($cuenta->tieneOperaciones()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'La cuenta tiene operaciones asociadas y no puede eliminarse; podés ocultarla.',
            ], 422);
        }

        DB::transaction(function () use ($cuenta) {
            $cuenta->movimientos()->forceDelete();
            $cuenta->delete();
        });

        return response()->json(['ok' => true, 'mensaje' => 'Cuenta eliminada.']);
    }

    /** Ficha/ledger de la cuenta (US4). */
    public function show(Request $request, CuentaTesoreria $cuenta)
    {
        $CurrentPage = 'tesoreria';
        $desde = $request->filled('desde') ? Carbon::parse($request->input('desde')) : Carbon::now()->local()->startOfDay()->subMonth();
        $hasta = $request->filled('hasta') ? Carbon::parse($request->input('hasta')) : Carbon::now()->local()->startOfDay();

        return view('tesoreria.cuenta', compact('CurrentPage', 'cuenta', 'desde', 'hasta'));
    }

    /**
     * Query base: movimientos de la cuenta + balance corrido calculado sobre
     * el histórico COMPLETO (sin filtros de pantalla), vía función de ventana
     * — mismo patrón que InformeStockController::baseQuery().
     */
    private function baseQuery(CuentaTesoreria $cuenta): \Illuminate\Database\Query\Builder
    {
        $ventana = DB::table('movimientos_tesoreria')
            ->where('cuenta_tesoreria_id', $cuenta->id)
            ->whereNull('deleted_at')
            ->selectRaw(
                'movimientos_tesoreria.*, SUM(monto) OVER (ORDER BY fecha, id) as balance'
            );

        return DB::query()
            ->fromSub($ventana, 'mov')
            ->leftJoin('users', 'users.id', '=', 'mov.usuario_id')
            ->select([
                'mov.id as id', 'mov.fecha as fecha', 'mov.tipo as tipo', 'mov.detalle as detalle',
                'mov.monto as monto', 'mov.balance as balance', 'mov.nro_comprobante as nro_comprobante',
                'mov.observacion as observacion', 'mov.transferencia_id as transferencia_id',
                'mov.origen_type as origen_type', 'users.name as usuario',
            ]);
    }

    /** Ledger paginado server-side (FR-020/FR-021/FR-022/FR-023). */
    public function data(Request $request, CuentaTesoreria $cuenta): JsonResponse
    {
        $query = $this->baseQuery($cuenta);

        if ($request->filled('tipo_operacion')) {
            $query->where('mov.tipo', $request->input('tipo_operacion'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('mov.fecha', '>=', $request->input('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('mov.fecha', '<=', $request->input('hasta'));
        }

        return DataTables::of($query)
            ->editColumn('monto', fn ($row) => (float) $row->monto)
            ->editColumn('balance', fn ($row) => (float) $row->balance)
            ->addColumn('ingreso', fn ($row) => $row->monto > 0 ? (float) $row->monto : null)
            ->addColumn('egreso', fn ($row) => $row->monto < 0 ? (float) (-$row->monto) : null)
            ->addColumn('operacion', fn ($row) => self::ETIQUETAS_OPERACION[$row->tipo] ?? $row->tipo)
            ->addColumn('es_nativo', fn ($row) => in_array($row->tipo, self::TIPOS_NATIVOS, true))
            ->addColumn('acciones', fn ($row) => view('tesoreria._row_actions', ['id' => $row->id])->render())
            ->rawColumns(['acciones'])
            ->order(function ($query) {
                $query->orderBy('mov.fecha')->orderBy('mov.id');
            })
            ->toJson();
    }

    private const ETIQUETAS_OPERACION = [
        'saldo_inicial' => 'Saldo Inicial',
        'movimiento_entre_cuentas' => 'Movimiento entre Cuenta',
        'cobro' => 'Cobro',
        'pago' => 'Pago',
        'gasto' => 'Gasto',
    ];

    /** Export del ledger de la cuenta a CSV (FR-025). */
    public function export(Request $request, CuentaTesoreria $cuenta): StreamedResponse
    {
        $query = $this->baseQuery($cuenta);
        if ($request->filled('tipo_operacion')) {
            $query->where('mov.tipo', $request->input('tipo_operacion'));
        }

        $nombreArchivo = 'ledger_'.str($cuenta->nombre)->slug().'_'.now()->format('Ymd_His').'.csv';
        $encabezados = ['Id', 'Fecha', 'Operación', 'Detalles', 'Ingreso', 'Egreso', 'Balance', 'N° Factura', 'Observación'];

        return response()->streamDownload(function () use ($query, $encabezados) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            foreach ($query->orderBy('mov.fecha')->orderBy('mov.id')->get() as $fila) {
                fputcsv($salida, [
                    $fila->id,
                    $fila->fecha,
                    self::ETIQUETAS_OPERACION[$fila->tipo] ?? $fila->tipo,
                    $fila->detalle,
                    $fila->monto > 0 ? $fila->monto : '',
                    $fila->monto < 0 ? -$fila->monto : '',
                    $fila->balance,
                    $fila->nro_comprobante,
                    $fila->observacion,
                ], ';');
            }

            fclose($salida);
        }, $nombreArchivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Edición de un movimiento nativo (fecha/monto/observación) — FR-024. */
    public function updateMovimiento(Request $request, MovimientoTesoreria $movimiento): JsonResponse
    {
        if (! $movimiento->esNativo()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Este movimiento se originó en otro módulo y no se edita desde Tesorería.',
            ], 422);
        }

        $datos = $request->validate([
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric'],
            'observacion' => ['nullable', 'string'],
        ]);

        $movimiento->update($datos);

        return response()->json(['ok' => true, 'mensaje' => 'Movimiento actualizado.', 'movimiento' => $movimiento->fresh()]);
    }

    /**
     * Elimina un movimiento nativo; si es una transferencia, borra ambas patas
     * por `transferencia_id` (FR-024, edge case). Los movimientos con origen
     * documental se soft-deletean (constitución, principio III).
     */
    public function destroyMovimiento(MovimientoTesoreria $movimiento): JsonResponse
    {
        if (! $movimiento->esNativo()) {
            $movimiento->delete(); // soft delete: preserva trazabilidad (principio III).

            return response()->json(['ok' => true, 'mensaje' => 'Movimiento eliminado.']);
        }

        DB::transaction(function () use ($movimiento) {
            if ($movimiento->transferencia_id) {
                MovimientoTesoreria::where('transferencia_id', $movimiento->transferencia_id)->forceDelete();
            } else {
                $movimiento->forceDelete();
            }
        });

        return response()->json(['ok' => true, 'mensaje' => 'Movimiento eliminado.']);
    }
}
