<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\VincularPublicacionRequest;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Yajra\DataTables\Facades\DataTables;

/**
 * Vinculación de publicaciones de Mercado Libre con productos del CRM
 * (FR-021..FR-027, contracts §2). Relación 1:1 — infraestructura compartida
 * con la spec 013.
 */
class MercadoLibreVinculacionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'ingresos-mercadolibre-vinculaciones';

        return view('ingresos.mercadolibre.vinculaciones', compact('CurrentPage'));
    }

    public function datatable(): JsonResponse
    {
        $query = MercadoLibrePublicacionProducto::query()->with('producto:id,nombre,codigo');

        // El stock que realmente se publica sale de UN depósito, no del total del
        // producto. Mostrar el total acá induce a error: se ve "17" en el CRM y "7"
        // en Mercado Libre y parece un problema de sincronización cuando no lo es.
        $depositoMl = MercadoLibreConfiguracion::actual()->depositoEfectivo();
        $stockService = app(StockService::class);

        return DataTables::eloquent($query)
            ->addColumn('producto_nombre', fn (MercadoLibrePublicacionProducto $v) => optional($v->producto)->nombre)
            ->addColumn('stock_ml', fn (MercadoLibrePublicacionProducto $v) => $v->producto
                ? (int) max(0, $stockService->disponibilidad($v->producto, null, $depositoMl))
                : null)
            ->addColumn('acciones', fn (MercadoLibrePublicacionProducto $v) => view('ingresos.mercadolibre._row_actions_vinculacion', ['vinculacion' => $v])->render())
            ->addColumn('stock_estado', fn (MercadoLibrePublicacionProducto $v) => $this->stockEstado($v))
            ->editColumn('created_at', fn (MercadoLibrePublicacionProducto $v) => $v->created_at->format('d/m/Y'))
            ->editColumn('stock_sincronizado_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->stock_sincronizado_en)->format('d/m/Y H:i'))
            ->editColumn('stock_error_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->stock_error_en)->format('d/m/Y H:i'))
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** Estado de sincronización de stock del vínculo (spec 013, FR-017). */
    private function stockEstado(MercadoLibrePublicacionProducto $v): string
    {
        if ($v->stock_error) {
            return 'error';
        }

        return $v->stock_pendiente ? 'pendiente' : 'sincronizado';
    }

    public function store(VincularPublicacionRequest $request): JsonResponse
    {
        $vinculacion = MercadoLibrePublicacionProducto::create([
            ...$request->validated(),
            'vinculada_por' => $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Publicación vinculada.',
            'vinculacion' => $vinculacion->load('producto:id,nombre,codigo'),
        ], 201);
    }

    public function update(VincularPublicacionRequest $request, MercadoLibrePublicacionProducto $vinculacion): JsonResponse
    {
        $vinculacion->update($request->validated());

        return response()->json([
            'ok' => true,
            'mensaje' => 'Vinculación actualizada.',
            'vinculacion' => $vinculacion->fresh()->load('producto:id,nombre,codigo'),
        ]);
    }

    /** Conserva intactas las órdenes ya convertidas con este vínculo (FR-026, FR-062). */
    public function destroy(MercadoLibrePublicacionProducto $vinculacion): JsonResponse
    {
        $ordenesConvertidas = MercadoLibreOrdenItem::where('ml_item_id', $vinculacion->ml_item_id)
            ->whereHas('orden', fn ($q) => $q->whereNotNull('venta_id'))
            ->count();

        $vinculacion->delete();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Vinculación eliminada.',
            'advertencia' => $ordenesConvertidas > 0
                ? "{$ordenesConvertidas} orden(es) ya convertidas conservan este producto. Las órdenes futuras de esta publicación quedarán sin resolver."
                : null,
        ]);
    }
}
