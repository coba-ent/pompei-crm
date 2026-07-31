<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\ImportarVinculacionesTiendanubeRequest;
use App\Http\Requests\Integraciones\VincularVarianteRequest;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Services\Tiendanube\ImportadorVinculaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Vinculación de variantes de Tiendanube con productos del CRM (FR-021..FR-026,
 * contracts §2). Relación 1:1 — infraestructura propia, independiente de la
 * de Mercado Libre (research.md R3).
 */
class TiendanubeVinculacionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'ingresos-tiendanube-vinculaciones';

        return view('ingresos.tiendanube.vinculaciones', compact('CurrentPage'));
    }

    public function datatable(): JsonResponse
    {
        $query = TiendanubeVarianteProducto::query()->with('producto:id,nombre,codigo');

        return DataTables::eloquent($query)
            ->addColumn('producto_nombre', fn (TiendanubeVarianteProducto $v) => optional($v->producto)->nombre)
            ->addColumn('acciones', fn (TiendanubeVarianteProducto $v) => view('ingresos.tiendanube._row_actions_vinculacion', ['vinculacion' => $v])->render())
            ->addColumn('stock_estado', fn (TiendanubeVarianteProducto $v) => $this->stockEstado($v))
            ->addColumn('precio_estado', fn (TiendanubeVarianteProducto $v) => $this->precioEstado($v))
            ->editColumn('created_at', fn (TiendanubeVarianteProducto $v) => $v->created_at->format('d/m/Y'))
            ->editColumn('stock_sincronizado_en', fn (TiendanubeVarianteProducto $v) => optional($v->stock_sincronizado_en)->format('d/m/Y H:i'))
            ->editColumn('stock_error_en', fn (TiendanubeVarianteProducto $v) => optional($v->stock_error_en)->format('d/m/Y H:i'))
            ->editColumn('precio_sincronizado_en', fn (TiendanubeVarianteProducto $v) => optional($v->precio_sincronizado_en)->format('d/m/Y H:i'))
            ->editColumn('precio_error_en', fn (TiendanubeVarianteProducto $v) => optional($v->precio_error_en)->format('d/m/Y H:i'))
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** Estado de sincronización de stock del vínculo (spec 018, FR-017). */
    private function stockEstado(TiendanubeVarianteProducto $v): string
    {
        if ($v->stock_error) {
            return 'error';
        }

        return $v->stock_pendiente ? 'pendiente' : 'sincronizado';
    }

    /** Estado de sincronización de precio del vínculo (spec 018 ampliación, FR-038). */
    private function precioEstado(TiendanubeVarianteProducto $v): string
    {
        if ($v->precio_error) {
            return 'error';
        }

        return $v->precio_pendiente ? 'pendiente' : 'sincronizado';
    }

    /**
     * Variantes vistas en órdenes sincronizadas que todavía no tienen vínculo
     * (para el selector con buscador de la pantalla de vinculación, FR-023):
     * esta spec no importa catálogo de Tiendanube (Alcance, Excluye), así que
     * las variantes disponibles para vincular salen de `tn_orden_items` ya
     * sincronizados, no de una consulta en vivo a la tienda.
     */
    public function variantesPendientes(Request $request)
    {
        $vinculadas = TiendanubeVarianteProducto::pluck('variant_id');

        $query = TiendanubeOrdenItem::whereNotIn('variant_id', $vinculadas)
            ->select('tn_product_id', 'variant_id', 'nombre_producto', 'nombre_variante')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $termino = $request->input('q');
            $query->where(fn ($q) => $q->where('nombre_producto', 'like', "%{$termino}%")
                ->orWhere('nombre_variante', 'like', "%{$termino}%"));
        }

        $variantes = $query->get()
            ->unique('variant_id')
            ->take(50)
            ->map(fn (TiendanubeOrdenItem $item) => [
                'id' => $item->variant_id,
                'text' => $item->nombre_producto.($item->nombre_variante ? " ({$item->nombre_variante})" : ''),
                'nombre' => $item->nombre_producto.($item->nombre_variante ? " ({$item->nombre_variante})" : ''),
                'tn_product_id' => $item->tn_product_id,
            ]);

        return response()->json(['data' => $variantes]);
    }

    /** FR-009..FR-016: importación masiva desde el export nativo de Tiendanube (contracts/rutas-internas.md). */
    public function importar(ImportarVinculacionesTiendanubeRequest $request, ImportadorVinculaciones $importador): JsonResponse
    {
        $resumen = $importador->importar($request->file('archivo')->getRealPath(), $request->user());

        return response()->json([
            'ok' => true,
            'mensaje' => "{$resumen['vinculadas']} de {$resumen['total']} vinculaciones creadas.",
            ...$resumen,
        ]);
    }

    public function store(VincularVarianteRequest $request): JsonResponse
    {
        $vinculacion = TiendanubeVarianteProducto::create([
            ...$request->validated(),
            'vinculada_por' => $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Variante vinculada.',
            'vinculacion' => $vinculacion->load('producto:id,nombre,codigo'),
        ], 201);
    }

    public function update(VincularVarianteRequest $request, TiendanubeVarianteProducto $vinculacion): JsonResponse
    {
        $vinculacion->update($request->validated());

        return response()->json([
            'ok' => true,
            'mensaje' => 'Vinculación actualizada.',
            'vinculacion' => $vinculacion->fresh()->load('producto:id,nombre,codigo'),
        ]);
    }

    /** Conserva intactas las órdenes ya convertidas con este vínculo (FR-026, FR-062). */
    public function destroy(TiendanubeVarianteProducto $vinculacion): JsonResponse
    {
        $ordenesConvertidas = TiendanubeOrdenItem::where('variant_id', $vinculacion->variant_id)
            ->whereHas('orden', fn ($q) => $q->whereNotNull('venta_id'))
            ->count();

        $vinculacion->delete();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Vinculación eliminada.',
            'advertencia' => $ordenesConvertidas > 0
                ? "{$ordenesConvertidas} orden(es) ya convertidas conservan este producto. Las órdenes futuras de esta variante quedarán sin resolver."
                : null,
        ]);
    }
}
