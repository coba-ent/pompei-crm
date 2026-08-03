<?php

namespace App\Http\Controllers\Mensajeria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mensajeria\EnviarRespuestaMercadoLibreRequest;
use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreSugerencia;
use App\Services\MercadoLibre\Mensajeria\EnvioRespuestaMercadoLibre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Bandeja unificada de Mensajería de Mercado Libre (spec 032): listado,
 * detalle de conversación, polling de actualizaciones y respuesta manual.
 */
class ConversacionController extends Controller
{
    public function __construct(private readonly EnvioRespuestaMercadoLibre $envio) {}

    public function index()
    {
        $CurrentPage = 'mensajeria';

        return view('mensajeria.index', compact('CurrentPage'));
    }

    public function datatable(Request $request): JsonResponse
    {
        $query = MercadoLibreConversacion::query()
            ->with(['publicacionProducto.producto:id,nombre', 'orden:id,ml_order_id'])
            ->orderByDesc('ultimo_mensaje_en');

        return DataTables::eloquent($query)
            ->addColumn('comprador', fn (MercadoLibreConversacion $c) => $c->comprador_nickname ?? $c->comprador_ml_id)
            ->addColumn('referencia', function (MercadoLibreConversacion $c) {
                if ($c->tipo === 'pregunta') {
                    return $c->publicacionProducto?->producto?->nombre ?? $c->publicacion_id_ml;
                }

                if ($c->orden) {
                    return 'Orden '.$c->orden->ml_order_id;
                }

                return $c->pack_id_ml ? 'Pack '.$c->pack_id_ml.' (sin sincronizar)' : '—';
            })
            ->addColumn('ultimo_mensaje', function (MercadoLibreConversacion $c) {
                $ultimo = $c->mensajes()->latest('enviado_en')->first();

                return $ultimo ? \Illuminate\Support\Str::limit($ultimo->texto, 80) : '';
            })
            ->editColumn('ultimo_mensaje_en', fn (MercadoLibreConversacion $c) => optional($c->ultimo_mensaje_en)->format('d/m/Y H:i'))
            ->filterColumn('comprador', function ($query, $palabra) {
                $query->where(function ($q) use ($palabra) {
                    $q->where('comprador_nickname', 'like', "%{$palabra}%")
                        ->orWhere('comprador_ml_id', 'like', "%{$palabra}%");
                });
            })
            ->toJson();
    }

    public function show(MercadoLibreConversacion $conversacion): JsonResponse
    {
        $conversacion->load(['mensajes', 'publicacionProducto.producto:id,nombre', 'orden:id,ml_order_id']);

        return response()->json(['ok' => true, 'conversacion' => $conversacion]);
    }

    /** Polling de conversaciones/mensajes nuevos desde `?desde=` (R6 de research.md). */
    public function actualizaciones(Request $request): JsonResponse
    {
        $desde = $request->input('desde');

        $conversaciones = MercadoLibreConversacion::query()
            ->when($desde, fn ($q) => $q->where('ultimo_mensaje_en', '>', $desde))
            ->orderBy('ultimo_mensaje_en')
            ->get(['id', 'estado', 'ultimo_mensaje_en']);

        $mensajes = MercadoLibreMensaje::query()
            ->when($desde, fn ($q) => $q->where('enviado_en', '>', $desde))
            ->orderBy('enviado_en')
            ->get(['id', 'ml_conversacion_id', 'origen', 'texto', 'enviado_en']);

        // Spec 033, US2 (R5 de research.md): mismo mecanismo de polling, se agrega el estado de
        // sugerencia sin tocar la forma de conversaciones/mensajes ya existente.
        $sugerencias = MercadoLibreSugerencia::query()
            ->when($desde, fn ($q) => $q->where('updated_at', '>', $desde))
            ->orderBy('updated_at')
            ->get(['id', 'ml_mensaje_id', 'estado', 'texto_sugerido', 'error_mensaje']);

        return response()->json([
            'ok' => true,
            'ahora' => now()->toJSON(),
            'conversaciones' => $conversaciones,
            'mensajes' => $mensajes,
            'sugerencias' => $sugerencias,
        ]);
    }

    public function responder(EnviarRespuestaMercadoLibreRequest $request, MercadoLibreConversacion $conversacion): JsonResponse
    {
        $resultado = $this->envio->enviar(
            $conversacion,
            $request->validated('texto'),
            $request->user()->id,
            $request->validated('sugerencia_id'),
        );

        return response()->json($resultado, $resultado['status']);
    }
}
