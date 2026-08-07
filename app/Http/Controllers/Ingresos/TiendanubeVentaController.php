<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\ConvertirOrdenTiendanubeRequest;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Venta;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use App\Services\Tiendanube\ReevaluadorOrdenes;
use App\Services\Tiendanube\ResolutorCliente;
use App\Services\Tiendanube\SincronizadorOrdenes;
use App\Services\Tiendanube\SincronizadorPrecios;
use App\Services\Tiendanube\SincronizadorStock;
use App\Models\Integraciones\TiendanubeOrden;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

/**
 * Ingresos → Tiendanube (spec 017): listado de órdenes sincronizadas,
 * sincronización manual y conversión a Venta (US1/US3, contracts §1).
 */
class TiendanubeVentaController extends Controller
{
    public function __construct(
        private readonly SincronizadorOrdenes $sincronizador,
        private readonly ConversorOrdenAVenta $conversor,
        private readonly ResolutorCliente $resolutorCliente,
        private readonly SincronizadorStock $sincronizadorStock,
        private readonly SincronizadorPrecios $sincronizadorPrecios,
        private readonly ReevaluadorOrdenes $reevaluador,
    ) {
    }

    public function index()
    {
        $CurrentPage = 'ingresos-tiendanube';

        return view('ingresos.tiendanube.index', compact('CurrentPage'));
    }

    public function datatable(Request $request): JsonResponse
    {
        // On-view (spec 041, FR-006/FR-007): red de seguridad para órdenes que quedaron
        // `requiere_atencion` desincronizadas porque su vinculación se creó después de
        // sincronizarlas y, por lo que sea, no pasaron por el Observer evento-driven.
        $this->reevaluador->reevaluarPendientesDelCanal(auth()->id());

        $query = TiendanubeOrden::query()->with(['items', 'venta:id,nro_comprobante']);

        if ($request->filled('estado_conversion')) {
            $query->where('estado_conversion', $request->input('estado_conversion'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('fecha_cerrada', '>=', $request->input('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha_cerrada', '<=', $request->input('hasta'));
        }

        return DataTables::eloquent($query)
            ->addColumn('acciones', fn (TiendanubeOrden $o) => view('ingresos.tiendanube._row_actions', ['orden' => $o])->render())
            ->addColumn('estado_conversion_label', fn (TiendanubeOrden $o) => $o->estado_conversion->etiqueta())
            ->addColumn('estado_conversion_color', fn (TiendanubeOrden $o) => $o->estado_conversion->color())
            ->addColumn('motivo_label', fn (TiendanubeOrden $o) => optional($o->motivo)->etiqueta())
            ->addColumn('comprador', fn (TiendanubeOrden $o) => $o->comprador_nombre ?? $o->comprador_email)
            ->addColumn('cliente_nuevo', fn (TiendanubeOrden $o) => $this->esClienteNuevo($o))
            ->addColumn('productos', fn (TiendanubeOrden $o) => $o->items->pluck('nombre_producto')->implode(', '))
            ->addColumn('venta_nro', fn (TiendanubeOrden $o) => optional($o->venta)->nro_comprobante)
            ->editColumn('fecha_cerrada', fn (TiendanubeOrden $o) => $o->fecha_cerrada?->local()->format('d/m/Y H:i'))
            ->editColumn('total', fn (TiendanubeOrden $o) => (float) $o->total)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** "Sincronizar ahora" (FR-009). */
    public function sincronizar(): JsonResponse
    {
        $resultado = $this->sincronizador->ejecutar();

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** "Sincronizar stock ahora" (spec 018, US3, contracts §1). */
    public function sincronizarStock(): JsonResponse
    {
        $resultado = $this->sincronizadorStock->ejecutar();

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** "Sincronizar precios ahora" (spec 018 ampliación, US7, contracts §1a) — ruta propia de Tiendanube. */
    public function sincronizarPrecios(): JsonResponse
    {
        $resultado = $this->sincronizadorPrecios->ejecutar();

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** "Transformar todas en Venta" (spec 025, FR-001/FR-002, contracts §1). */
    public function transformarTodasEnVenta(Request $request): JsonResponse
    {
        $resultado = $this->conversor->convertirTodasLasListas($request->user()->id);

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** Detalle de la orden en modal (FR-005). */
    public function show(TiendanubeOrden $orden): JsonResponse
    {
        $orden->load(['items.producto', 'venta:id,nro_comprobante']);
        $orden->cliente_nuevo = $this->esClienteNuevo($orden);

        return response()->json(['ok' => true, 'orden' => $orden]);
    }

    /**
     * Aviso NO bloqueante "Cliente nuevo": se recalcula en vivo, igual criterio
     * que `MercadoLibreVentaController` (spec 012).
     */
    private function esClienteNuevo(TiendanubeOrden $orden): bool
    {
        ['cliente' => $cliente, 'ambiguo' => $ambiguo] = $this->resolutorCliente->buscarExistente($orden);

        return ! $cliente && ! $ambiguo;
    }

    /** Formulario de conversión precargado (FR-028/FR-029). */
    public function convertir(TiendanubeOrden $orden)
    {
        $CurrentPage = 'ingresos-tiendanube';

        if (! $orden->estado_conversion->habilitaCrearVenta()) {
            abort(409, 'Esta orden no está lista para convertir: '.($orden->motivo_detalle ?? 'revisá su estado.'));
        }

        $preview = $this->conversor->previsualizar($orden);
        $submitToken = (string) Str::uuid();

        return view('ingresos.tiendanube.convertir', compact('CurrentPage', 'orden', 'preview', 'submitToken'));
    }

    /** Ejecuta la conversión (FR-032, FR-044, FR-046). */
    public function convertirGuardar(ConvertirOrdenTiendanubeRequest $request, TiendanubeOrden $orden): JsonResponse
    {
        $datos = $request->validated();

        if (Venta::withTrashed()->where('submit_token', $datos['submit_token'])->exists()) {
            $existente = Venta::withTrashed()->where('submit_token', $datos['submit_token'])->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'Venta '.$existente->nro_comprobante.' creada con éxito.',
                'redirect' => route('ventas.show', $existente),
            ], 201);
        }

        try {
            foreach ($datos['vinculaciones_inline'] ?? [] as $vinculacion) {
                TiendanubeVarianteProducto::firstOrCreate(
                    ['variant_id' => $vinculacion['variant_id']],
                    [
                        'producto_id' => $vinculacion['producto_id'],
                        'vinculada_por' => $request->user()->id,
                        // spec 018, research.md R6: se completa acá igual que en la
                        // pantalla de vinculación dedicada — el ítem de la propia
                        // orden que se está convirtiendo ya trae tn_product_id.
                        'tn_product_id' => TiendanubeOrdenItem::where('variant_id', $vinculacion['variant_id'])->value('tn_product_id'),
                    ]
                );
            }
        } catch (QueryException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo vincular la variante.',
                'errors' => ['vinculaciones_inline' => ['El producto elegido ya está vinculado a otra variante.']],
            ], 422);
        }

        $resultado = $this->conversor->convertir(
            $orden,
            $request->user()->id,
            automatica: false,
            clienteIdOverride: $datos['cliente_id'] ?? null,
            tipoComprobanteOverride: $datos['tipo_comprobante'] ?? null,
        );

        if (! $resultado['ok']) {
            return response()->json($resultado, 409);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Venta '.$resultado['venta']->nro_comprobante.' creada con éxito.',
            'redirect' => route('ventas.show', $resultado['venta']),
        ], 201);
    }
}
