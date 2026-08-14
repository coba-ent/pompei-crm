<?php

namespace App\Http\Controllers\Ingresos;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\ConvertirOrdenRequest;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Venta;
use App\Services\AuditoriaService;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\MercadoLibre\ReevaluadorOrdenes;
use App\Services\MercadoLibre\ResolutorCliente;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use App\Services\MercadoLibre\SincronizadorPrecios;
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
        private readonly SincronizadorPrecios $sincronizadorPrecios,
        private readonly ResolutorCliente $resolutorCliente,
        private readonly ReevaluadorOrdenes $reevaluador,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function index()
    {
        $CurrentPage = 'ingresos-mercadolibre';

        return view('ingresos.mercadolibre.index', compact('CurrentPage'));
    }

    public function datatable(Request $request): JsonResponse
    {
        // On-view (spec 041, FR-006/FR-007): red de seguridad para órdenes que quedaron
        // `requiere_atencion` desincronizadas porque su vinculación se creó después de
        // sincronizarlas y, por lo que sea, no pasaron por el Observer evento-driven.
        $this->reevaluador->reevaluarPendientesDelCanal(auth()->id());

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
            // Se recalcula en vivo (no se confía en el snapshot `cliente_nuevo` que se
            // persistió al sincronizar): el Cliente puede haberse dado de alta después,
            // por ejemplo al convertir otra orden del mismo comprador.
            ->addColumn('cliente_nuevo', fn (MercadoLibreOrden $o) => $this->esClienteNuevo($o))
            ->addColumn('productos', fn (MercadoLibreOrden $o) => $o->items->pluck('titulo')->implode(', '))
            ->addColumn('venta_nro', fn (MercadoLibreOrden $o) => optional($o->venta)->nro_comprobante)
            ->editColumn('fecha_cerrada', fn (MercadoLibreOrden $o) => $o->fecha_cerrada?->local()->format('d/m/Y H:i'))
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

    /** "Sincronizar precios ahora" (spec 016, US3, FR-014, contracts §1). */
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
    public function show(MercadoLibreOrden $orden): JsonResponse
    {
        $orden->load(['items.producto', 'venta:id,nro_comprobante']);
        $orden->cliente_nuevo = $this->esClienteNuevo($orden);

        return response()->json(['ok' => true, 'orden' => $orden]);
    }

    /**
     * Aviso NO bloqueante "Cliente nuevo": se recalcula en vivo contra el estado
     * actual de `clientes` en cada lectura, en vez de confiar en el snapshot que
     * `SincronizadorOrdenes` persiste al momento de sincronizar. Así, dos órdenes
     * del mismo comprador siempre muestran el mismo resultado, y el aviso
     * desaparece apenas el Cliente se da de alta (por esta orden o por otra).
     */
    private function esClienteNuevo(MercadoLibreOrden $orden): bool
    {
        ['cliente' => $cliente, 'ambiguo' => $ambiguo] = $this->resolutorCliente->buscarExistente($orden);

        return ! $cliente && ! $ambiguo;
    }

    /** Formulario de conversión precargado (FR-028/FR-029). */
    public function convertir(MercadoLibreOrden $orden)
    {
        $CurrentPage = 'ingresos-mercadolibre';

        // spec 066 (FR-020): una orden en estado excepcional SÍ abre el formulario — es
        // justamente el caso que la persona tiene que poder resolver a mano. Lo que se sigue
        // rechazando son los problemas de datos (publicación sin vincular, etc.): ahí no hay
        // nada que confirmar, hay algo que arreglar antes.
        $motivoExcepcional = $orden->motivoExcepcional();

        if (! $orden->estado_conversion->habilitaCrearVenta() && ! $motivoExcepcional) {
            abort(409, 'Esta orden no está lista para convertir: '.($orden->motivo_detalle ?? 'revisá su estado.'));
        }

        $preview = $this->conversor->previsualizar($orden);
        $submitToken = (string) Str::uuid();

        return view('ingresos.mercadolibre.convertir', [
            'CurrentPage' => $CurrentPage,
            'orden' => $orden,
            'preview' => $preview,
            'submitToken' => $submitToken,
            'requiere_confirmacion' => $motivoExcepcional !== null,
            'motivo_excepcional' => $motivoExcepcional?->value,
            'motivo_etiqueta' => $motivoExcepcional?->etiqueta(),
        ]);
    }

    /**
     * "Descartar aviso" (spec 063, T013/T014, FR-010/FR-011): deja la Venta vigente tal cual está
     * y devuelve la orden a `Convertida`, sin ejecutar ningún circuito de reversión propio
     * (FR-009a) — la persona decidió que no correspondía anular nada. Se registra en auditoría
     * quién y cuándo, con el motivo original (FR-011); las notas de crédito y la eliminación ya
     * tienen su propia auditoría, no se duplica acá.
     */
    public function descartarAviso(MercadoLibreOrden $orden): JsonResponse
    {
        if ($orden->estado_conversion !== EstadoConversion::RequiereAtencion
            || ! in_array($orden->motivo, MotivoRequiereAtencion::motivosDeCancelacionPosterior(), true)) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esta orden no tiene un aviso de cancelación pendiente para descartar.',
            ], 409);
        }

        $motivoOriginal = $orden->motivo->etiqueta();

        $orden->update([
            'estado_conversion' => EstadoConversion::Convertida->value,
            'motivo' => null,
            'motivo_detalle' => null,
        ]);

        $this->auditoria->registrarEvento(
            tipoAccion: 'descartar',
            tipoOperacion: 'ml_orden_aviso_cancelacion',
            entidad: $orden,
            detalle: "Se descartó el aviso de \"{$motivoOriginal}\" de la orden {$orden->ml_order_id}. La Venta ".
                optional($orden->venta)->nro_comprobante." queda vigente sin cambios.",
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Aviso descartado. La Venta sigue vigente.',
        ]);
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

        // spec 066 (FR-010/FR-015): la barrera vive acá, en el servidor, no en el modal. Este
        // endpoint se puede llamar directo sin pasar por la interfaz, así que el estado se
        // evalúa CONTRA LA BASE en este momento — no contra lo que la pantalla vio cuando se
        // abrió. Si la orden entró en mediación entre que se mostró el aviso y se confirmó,
        // el pedido se rechaza y la persona vuelve a decidir con la información nueva.
        $motivoExcepcional = $orden->fresh()->motivoExcepcional();
        $forzar = (bool) ($datos['forzar_conversion'] ?? false);

        if ($motivoExcepcional && ! $forzar) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esta orden está en un estado que requiere tu decisión: '.
                    lcfirst($motivoExcepcional->etiqueta()).'. Confirmá la conversión para continuar.',
                'requiere_confirmacion' => true,
                'motivo' => $motivoExcepcional->value,
            ], 409);
        }

        $resultado = $this->conversor->convertir(
            $orden,
            $request->user()->id,
            automatica: false,
            clienteIdOverride: $datos['cliente_id'] ?? null,
            tipoComprobanteOverride: $datos['tipo_comprobante'] ?? null,
            forzada: $forzar,
        );

        if (! $resultado['ok']) {
            return response()->json($resultado, 409);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Venta '.$resultado['venta']->nro_comprobante.' creada con éxito.',
            'redirect' => route('ventas.show', $resultado['venta']),
            'forzada' => $resultado['forzada'] ?? false,
            'motivo' => $resultado['motivo'] ?? null,
            // FR-021: la Venta forzada nace SIN comprobante emitido. Emitir sobre una orden
            // cancelada es una decisión aparte y deliberada, no un efecto de convertir.
            'comprobante_emitido' => false,
        ], 201);
    }
}
