<?php

namespace App\Http\Controllers;

use App\Exports\InformeCsvExport;
use App\Models\LogAuditoria;
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
