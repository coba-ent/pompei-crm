<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\ConvertirOrdenRequest;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Venta;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use App\Services\MercadoLibre\SincronizadorStock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

/**
 * Ingresos → Mercado Libre (spec 012/013): listado de órdenes sincronizadas,
 * sincronización manual (de órdenes y de stock) y conversión a Venta (US3,
 * contracts §1).
 */
class MercadoLibreVentaController extends Controller
{
    public function __construct(
        private readonly SincronizadorOrdenes $sincronizador,
        private readonly ConversorOrdenAVenta $conversor,
        private readonly SincronizadorStock $sincronizadorStock,
    ) {
    }

    public function index()
    {
        $CurrentPage = 'ingresos-mercadolibre';

        return view('ingresos.mercadolibre.index', compact('CurrentPage'));
    }

    public function datatable(Request $request): JsonResponse
    {
        $query = MercadoLibreOrden::query()->with(['items', 'venta:id,nro_comprobante']);

        if ($request->filled('estado_orden')) {
            $query->where('estado_orden', $request->input('estado_orden'));
        }
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
            ->addColumn('acciones', fn (MercadoLibreOrden $o) => view('ingresos.mercadolibre._row_actions', ['orden' => $o])->render())
            ->addColumn('estado_orden_label', fn (MercadoLibreOrden $o) => $o->estado_orden->etiqueta())
            ->addColumn('estado_conversion_label', fn (MercadoLibreOrden $o) => $o->estado_conversion->etiqueta())
            ->addColumn('estado_conversion_color', fn (MercadoLibreOrden $o) => $o->estado_conversion->color())
            ->addColumn('motivo_label', fn (MercadoLibreOrden $o) => optional($o->motivo)->etiqueta())
            ->addColumn('comprador', fn (MercadoLibreOrden $o) => $o->comprador_nombre ?? $o->comprador_apodo)
            // Aviso NO bloqueante: al convertir se va a dar de alta un Cliente nuevo.
            ->addColumn('cliente_nuevo', fn (MercadoLibreOrden $o) => (bool) $o->cliente_nuevo)
            ->addColumn('productos', fn (MercadoLibreOrden $o) => $o->items->pluck('titulo')->implode(', '))
            ->addColumn('venta_nro', fn (MercadoLibreOrden $o) => optional($o->venta)->nro_comprobante)
            ->editColumn('fecha_cerrada', fn (MercadoLibreOrden $o) => optional($o->fecha_cerrada)->format('d/m/Y H:i'))
            ->editColumn('total', fn (MercadoLibreOrden $o) => (float) $o->total)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** "Sincronizar ahora" (FR-009). */
    public function sincronizar(): JsonResponse
    {
        $resultado = $this->sincronizador->ejecutar();

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** "Sincronizar stock ahora" (spec 013, US3, FR-007, contracts §1). */
    public function sincronizarStock(): JsonResponse
    {
        $resultado = $this->sincronizadorStock->ejecutar();

        return response()->json($resultado, $resultado['ok'] ? 200 : 409);
    }

    /** Detalle de la orden en modal (FR-005). */
    public function show(MercadoLibreOrden $orden): JsonResponse
    {
        $orden->load(['items.producto', 'venta:id,nro_comprobante']);

        return response()->json(['ok' => true, 'orden' => $orden]);
    }

    /** Formulario de conversión precargado (FR-028/FR-029). */
    public function convertir(MercadoLibreOrden $orden)
    {
        $CurrentPage = 'ingresos-mercadolibre';

        if (! $orden->estado_conversion->habilitaCrearVenta()) {
            abort(409, 'Esta orden no está lista para convertir: '.($orden->motivo_detalle ?? 'revisá su estado.'));
        }

        $preview = $this->conversor->previsualizar($orden);
        $submitToken = (string) Str::uuid();

        return view('ingresos.mercadolibre.convertir', compact('CurrentPage', 'orden', 'preview', 'submitToken'));
    }

    /** Ejecuta la conversión (FR-032, FR-044, FR-046). */
    public function convertirGuardar(ConvertirOrdenRequest $request, MercadoLibreOrden $orden): JsonResponse
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
                MercadoLibrePublicacionProducto::firstOrCreate(
                    ['ml_item_id' => $vinculacion['ml_item_id']],
                    ['producto_id' => $vinculacion['producto_id'], 'vinculada_por' => $request->user()->id]
                );
            }
        } catch (QueryException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo vincular la publicación.',
                'errors' => ['vinculaciones_inline' => ['El producto elegido ya está vinculado a otra publicación.']],
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
