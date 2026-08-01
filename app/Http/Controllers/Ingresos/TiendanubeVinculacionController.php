<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\VincularVarianteRequest;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Services\Tiendanube\Excepciones\VinculacionAutomaticaFallidaException;
use App\Services\Tiendanube\VinculadorAutomatico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Vinculación de variantes de Tiendanube con productos del CRM (FR-021..FR-026,
 * contracts §2). Relación 1:1 — infraestructura propia, independiente de la
 * de Mercado Libre (research.md R3). Desde spec 024, la resolución de
 * vínculos nuevos pasa por `VinculadorAutomatico` (catálogo REST en vivo);
 * el selector manual/importación por Excel se retiraron.
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

    /** FR-001..FR-013: reemplaza al selector manual y a la importación por Excel (contracts/rutas-internas.md). */
    public function vincularAutomaticamente(Request $request, VinculadorAutomatico $vinculador): JsonResponse
    {
        try {
            $resumen = $vinculador->ejecutar($request->user());
        } catch (VinculacionAutomaticaFallidaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => "{$resumen['vinculadas']} de {$resumen['total']} variantes vinculadas.",
            ...$resumen,
        ]);
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
