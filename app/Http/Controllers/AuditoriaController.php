<?php

namespace App\Http\Controllers;

use App\Exports\InformeCsvExport;
use App\Models\LogAuditoria;
use App\Models\MovimientoStock;
use App\Models\MovimientoTesoreria;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

/** Pantalla "Auditoría" (spec 054) — de solo lectura, sin alta/edición/eliminación (FR-007). */
class AuditoriaController extends Controller
{
    private const LABELS_ACCION = [
        'creo' => 'Creó',
        'modifico' => 'Modificó',
        'elimino' => 'Eliminó',
        'anulo' => 'Anuló',
    ];

    private const LABELS_OPERACION = [
        'venta' => 'Venta',
        'presupuesto' => 'Presupuesto',
        'cobro' => 'Cobro',
        'gasto' => 'Gasto',
        'compra' => 'Compra',
        'movimiento_tesoreria' => 'Movimiento de Tesorería',
        'movimiento_stock' => 'Movimiento de Stock',
    ];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function index()
    {
        $CurrentPage = 'auditoria';
        $usuarios = User::activos()->orderBy('name')->get(['id', 'name']);
        $operaciones = self::LABELS_OPERACION;
        $hoy = now()->toDateString();

        return view('auditoria.index', compact('CurrentPage', 'usuarios', 'operaciones', 'hoy'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = $this->auditoria->queryFiltrado($this->filtros($request))->with('usuario:id,name');

        return DataTables::eloquent($query)
            ->addColumn('fecha_hora', fn (LogAuditoria $log) => $log->created_at?->local()->format('d/m/Y H:i'))
            ->addColumn('tipo_accion_label', fn (LogAuditoria $log) => self::LABELS_ACCION[$log->tipo_accion] ?? $log->tipo_accion)
            ->addColumn('tipo_operacion_label', fn (LogAuditoria $log) => self::LABELS_OPERACION[$log->tipo_operacion] ?? $log->tipo_operacion)
            ->editColumn('total', fn (LogAuditoria $log) => $log->total !== null ? (float) $log->total : null)
            ->toJson();
    }

    public function exportar(Request $request): StreamedResponse|JsonResponse
    {
        $filas = $this->auditoria->queryFiltrado($this->filtros($request))->get();

        if ($filas->isEmpty()) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay datos para exportar con el filtro aplicado.'], 422);
        }

        $filas = $filas->map(fn (LogAuditoria $log) => [
            $log->id,
            $log->created_at?->local()->format('d/m/Y H:i'),
            $log->usuario_nombre,
            self::LABELS_ACCION[$log->tipo_accion] ?? $log->tipo_accion,
            self::LABELS_OPERACION[$log->tipo_operacion] ?? $log->tipo_operacion,
            $log->detalle,
            $log->total !== null ? (float) $log->total : '',
        ]);

        return (new InformeCsvExport)->stream(
            ['Id', 'Fecha y Hora', 'Usuario', 'Tipo', 'Operación', 'Detalle', 'Total'],
            $filas,
            'auditoria_'.now()->format('Y-m-d_His').'.csv'
        );
    }

    /**
     * Detalle de "qué pasó" para un evento puntual (stock: qué producto/depósito y cuánto;
     * tesorería: cuánto y de qué caja a qué caja si es transferencia). Sólo lectura.
     */
    public function detalle(LogAuditoria $log): JsonResponse
    {
        $datos = match ($log->entidad_tipo) {
            MovimientoStock::class => $this->detalleMovimientoStock($log->entidad_id),
            MovimientoTesoreria::class => $this->detalleMovimientoTesoreria($log->entidad_id),
            default => null,
        };

        return response()->json([
            'ok' => true,
            'log' => [
                'id' => $log->id,
                'fecha_hora' => $log->created_at?->local()->format('d/m/Y H:i'),
                'usuario' => $log->usuario_nombre,
                'accion' => self::LABELS_ACCION[$log->tipo_accion] ?? $log->tipo_accion,
                'operacion' => self::LABELS_OPERACION[$log->tipo_operacion] ?? $log->tipo_operacion,
                'detalle' => $log->detalle,
                'total' => $log->total !== null ? (float) $log->total : null,
            ],
            'tipo' => $log->entidad_tipo === MovimientoStock::class ? 'stock'
                : ($log->entidad_tipo === MovimientoTesoreria::class ? 'tesoreria' : null),
            'datos' => $datos,
        ]);
    }

    private function detalleMovimientoStock(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        $movimiento = MovimientoStock::with(['producto:id,nombre', 'deposito:id,nombre'])->find($id);

        if (! $movimiento) {
            return null;
        }

        return [
            'producto' => optional($movimiento->producto)->nombre ?? 'Producto eliminado',
            'deposito' => optional($movimiento->deposito)->nombre ?? 'Depósito eliminado',
            'tipo' => $movimiento->tipo,
            'cantidad' => (float) $movimiento->cantidad,
            'descripcion' => $movimiento->descripcion,
            'fecha' => $movimiento->fecha?->format('d/m/Y H:i'),
        ];
    }

    private function detalleMovimientoTesoreria(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        $movimiento = MovimientoTesoreria::withTrashed()->with('cuenta:id,nombre')->find($id);

        if (! $movimiento) {
            return null;
        }

        $par = $movimiento->transferencia_id
            ? MovimientoTesoreria::withTrashed()->with('cuenta:id,nombre')
                ->where('transferencia_id', $movimiento->transferencia_id)
                ->where('id', '!=', $movimiento->id)
                ->first()
            : null;

        $datos = [
            'cuenta' => optional($movimiento->cuenta)->nombre ?? 'Cuenta eliminada',
            'tipo' => $movimiento->tipo,
            'monto' => (float) $movimiento->monto,
            'concepto' => $movimiento->detalle,
            'observacion' => $movimiento->observacion,
            'fecha' => $movimiento->fecha?->format('d/m/Y'),
            'es_transferencia' => (bool) $par,
        ];

        if ($par) {
            $origen = $movimiento->monto < 0 ? $movimiento : $par;
            $destino = $movimiento->monto < 0 ? $par : $movimiento;
            $datos['transferencia'] = [
                'monto' => abs((float) $movimiento->monto),
                'caja_origen' => optional($origen->cuenta)->nombre ?? 'Cuenta eliminada',
                'caja_destino' => optional($destino->cuenta)->nombre ?? 'Cuenta eliminada',
            ];
        }

        return $datos;
    }

    private function filtros(Request $request): array
    {
        $usuarioId = $request->string('usuario_id')->toString();
        $origenSistema = null;

        if ($usuarioId === 'ml') {
            $origenSistema = 'mercadolibre';
            $usuarioId = null;
        } elseif ($usuarioId === 'tn') {
            $origenSistema = 'tiendanube';
            $usuarioId = null;
        } else {
            $usuarioId = $usuarioId !== '' ? (int) $usuarioId : null;
        }

        return [
            'id' => $request->integer('id') ?: null,
            'operacion' => $request->string('operacion')->toString() ?: null,
            'usuario_id' => $usuarioId,
            'origen_sistema' => $origenSistema,
            'fecha_desde' => $request->string('fecha_desde')->toString() ?: null,
            'fecha_hasta' => $request->string('fecha_hasta')->toString() ?: null,
        ];
    }
}
