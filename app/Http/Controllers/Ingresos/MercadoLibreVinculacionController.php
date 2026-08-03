<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\VincularPublicacionRequest;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\MercadoLibre\Excepciones\VinculacionAutomaticaFallidaException;
use App\Services\MercadoLibre\SincronizadorPrecios;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\MercadoLibre\VinculadorAutomatico;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Vinculación de publicaciones de Mercado Libre con productos del CRM
 * (FR-021..FR-027, contracts §2). Relación 1:1 — infraestructura compartida
 * con la spec 013.
 */
class MercadoLibreVinculacionController extends Controller
{
    public function __construct(
        private readonly SincronizadorStock $sincronizadorStock,
        private readonly SincronizadorPrecios $sincronizadorPrecios,
    ) {
    }

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
            ->addColumn('precio_estado', fn (MercadoLibrePublicacionProducto $v) => $this->precioEstado($v))
            ->editColumn('created_at', fn (MercadoLibrePublicacionProducto $v) => $v->created_at->format('d/m/Y'))
            ->editColumn('stock_sincronizado_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->stock_sincronizado_en)->format('d/m/Y H:i'))
            ->editColumn('stock_error_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->stock_error_en)->format('d/m/Y H:i'))
            ->editColumn('precio_sincronizado_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->precio_sincronizado_en)->format('d/m/Y H:i'))
            ->editColumn('precio_error_en', fn (MercadoLibrePublicacionProducto $v) => optional($v->precio_error_en)->format('d/m/Y H:i'))
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

    /** Estado de sincronización de precio del vínculo (spec 016, FR-017). */
    private function precioEstado(MercadoLibrePublicacionProducto $v): string
    {
        if ($v->precio_error) {
            return 'error';
        }

        return $v->precio_pendiente ? 'pendiente' : 'sincronizado';
    }

    /**
     * Publicaciones vistas en órdenes sincronizadas que todavía no tienen
     * vínculo (para el selector con buscador de la pantalla de vinculación) —
     * mismo criterio que TiendanubeVinculacionController::variantesPendientes:
     * no hay endpoint de catálogo en alcance, sale de ml_orden_items ya
     * sincronizados. Excluye publicaciones con variante (FR-027, no soportadas).
     */
    public function publicacionesPendientes(Request $request)
    {
        $vinculadas = MercadoLibrePublicacionProducto::pluck('ml_item_id');

        $query = MercadoLibreOrdenItem::whereNotIn('ml_item_id', $vinculadas)
            ->whereNull('ml_variation_id')
            ->select('ml_item_id', 'titulo')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $termino = $request->input('q');
            $query->where(fn ($q) => $q->where('titulo', 'like', "%{$termino}%")
                ->orWhere('ml_item_id', 'like', "%{$termino}%"));
        }

        $publicaciones = $query->get()
            ->unique('ml_item_id')
            ->take(50)
            ->map(fn (MercadoLibreOrdenItem $item) => [
                'id' => $item->ml_item_id,
                'text' => $item->ml_item_id.' — '.$item->titulo,
                'titulo' => $item->titulo,
            ]);

        return response()->json(['data' => $publicaciones]);
    }

    /** FR-001..FR-005: reemplaza al alta manual por selector (ver contracts/rutas-internas.md). */
    public function vincularAutomaticamente(Request $request, VinculadorAutomatico $vinculador): JsonResponse
    {
        try {
            $resumen = $vinculador->ejecutar($request->user());
        } catch (VinculacionAutomaticaFallidaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => "{$resumen['vinculadas']} de {$resumen['total']} publicaciones vinculadas.",
            ...$resumen,
        ]);
    }

    /**
     * "Sincronización forzada" (spec 035, FR-001..FR-014): recorre TODOS los
     * vínculos (no sólo pendientes) y reenvía stock y precio reales. Reusa
     * los mismos cortes y candados de `SincronizadorStock`/`SincronizadorPrecios`
     * — si el stock queda bloqueado, no se intenta precio.
     */
    public function sincronizacionForzada(): JsonResponse
    {
        $resultadoStock = $this->sincronizadorStock->sincronizarTodos();

        if (! $resultadoStock['ok']) {
            return response()->json($resultadoStock, 409);
        }

        $configuracion = MercadoLibreConfiguracion::actual();
        $resultadoPrecio = null;

        if ($configuracion->lista_precio_id) {
            $resultadoPrecio = $this->sincronizadorPrecios->sincronizarListaCompleta($configuracion->lista_precio_id);
        }

        // Un rechazo de credencial durante la fase de stock puede bloquear la
        // cuenta a mitad de la corrida y frenar la fase de precio que corre
        // después — ese resultado no trae 'actualizados'/'con_error', se
        // informa como precio no intentado (mismo criterio que Tiendanube).
        $precioBloqueado = $resultadoPrecio && ! ($resultadoPrecio['ok'] ?? false);

        $mensaje = match (true) {
            $precioBloqueado => "{$resultadoStock['mensaje']} (stock) — precio no sincronizado: {$resultadoPrecio['mensaje']}",
            (bool) $resultadoPrecio => "{$resultadoStock['mensaje']} (stock) — {$resultadoPrecio['mensaje']} (precio)",
            default => "{$resultadoStock['mensaje']} (stock) — sin lista de precios configurada, precio no sincronizado.",
        };

        return response()->json([
            'ok' => true,
            'mensaje' => $mensaje,
            'stock' => ['actualizados' => $resultadoStock['actualizados'], 'con_error' => $resultadoStock['con_error']],
            'precio' => ($resultadoPrecio && ! $precioBloqueado)
                ? ['actualizados' => $resultadoPrecio['actualizados'], 'con_error' => $resultadoPrecio['con_error']]
                : null,
        ]);
    }

    /**
     * "Eliminar todas las vinculaciones" (spec 035, FR-015..FR-022): borrado
     * atómico del lado CRM únicamente, sin request hacia Mercado Libre (FR-017).
     * No aplica los cortes de función desactivada/modo sólo lectura (FR-020,
     * no hay escritura externa), sólo el de conexión y el candado compartido
     * con las sincronizaciones (mismo `LOCK_KEY` de stock, para no borrar
     * vínculos que un sincronizador concurrente está leyendo/actualizando).
     */
    public function eliminarTodas(): JsonResponse
    {
        if (! MercadoLibreCuenta::conectada()->exists()) {
            return response()->json(['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'No hay ninguna cuenta de Mercado Libre conectada. Volvé a conectar la cuenta.'], 409);
        }

        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);

        if (! $lock->get()) {
            return response()->json(['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización en curso.'], 409);
        }

        try {
            $eliminados = DB::transaction(function () {
                $total = MercadoLibrePublicacionProducto::count();
                MercadoLibrePublicacionProducto::query()->delete();

                return $total;
            });

            MercadoLibreOperacionLog::registrar([
                'operacion' => 'eliminar_todas_vinculaciones',
                'metodo' => 'DELETE',
                'endpoint' => '-',
                'sentido' => 'interno',
                'resultado' => 'ok',
                'usuario_id' => auth()->id(),
                'payload_bloqueado' => "{$eliminados} vinculaciones eliminadas.",
            ]);

            return response()->json(['ok' => true, 'mensaje' => "{$eliminados} vinculaciones eliminadas.", 'eliminados' => $eliminados]);
        } finally {
            $lock->release();
        }
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
