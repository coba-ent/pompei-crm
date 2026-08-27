<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Services\MercadoLibre\SincronizadorPrecios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolución de los precios que el corte de seguridad frenó (spec 084, US1).
 *
 * Todo responde JSON y se consume por AJAX desde la pantalla de Vinculaciones, sin recargar
 * (CLAUDE.md §2). Las tres acciones —listar, aprobar, rechazar— son las únicas formas de levantar
 * una retención desde la interfaz.
 */
class MercadoLibreRetencionPrecioController extends Controller
{
    public function __construct(private readonly SincronizadorPrecios $sincronizador) {}

    /** DataTables server-side de las retenciones sin resolver (FR-011). */
    public function index(Request $request): JsonResponse
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        $base = MercadoLibreRetencionPrecio::query()
            ->abiertas()
            ->with(['publicacion.producto', 'listaPrecio']);

        $total = (clone $base)->count();

        if ($buscado = trim((string) $request->input('search.value'))) {
            $base->whereHas('publicacion', function ($q) use ($buscado) {
                $q->where('ml_item_id', 'like', "%{$buscado}%")
                    ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$buscado}%")
                        ->orWhere('codigo', 'like', "%{$buscado}%"));
            });
        }

        $filtrados = (clone $base)->count();

        $filas = $base->orderByDesc('caida_pct')
            ->skip((int) $request->input('start', 0))
            ->take(min((int) $request->input('length', 25) ?: 25, 100))
            ->get()
            ->map(fn (MercadoLibreRetencionPrecio $r) => [
                'id' => $r->id,
                'ml_item_id' => $r->publicacion?->ml_item_id,
                'producto' => trim(($r->publicacion?->producto?->codigo ?? '').' — '.($r->publicacion?->producto?->nombre ?? ''), ' —'),
                'tipo_publicacion' => $r->publicacion?->esPremium() ? 'Premium' : 'Clásica',
                'lista' => $r->listaPrecio?->nombre,
                'precio_publicado' => $r->precio_publicado === null ? null : (float) $r->precio_publicado,
                'precio_propuesto' => (float) $r->precio_propuesto,
                'precio_vigente_lista' => $this->precioVigente($r, $configuracion),
                'caida_pct' => $r->caida_pct === null ? null : (float) $r->caida_pct,
                'umbral_pct' => (float) $r->umbral_pct,
                'motivo' => $r->motivo,
                'motivo_legible' => $r->motivoLegible(),
                'retenida_en' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtrados,
            'data' => $filas,
            'resumen' => [
                'abiertas' => $total,
                'caida_maxima_pct' => (float) MercadoLibreRetencionPrecio::abiertas()->max('caida_pct'),
            ],
        ]);
    }

    /**
     * Aprobar: publica y cierra (FR-012).
     *
     * Se envía el precio **vigente** de la lista, no el que quedó congelado al retener. Publicar el
     * congelado sería mandar a Mercado Libre un precio sobre el que el negocio ya cambió de
     * opinión — el error opuesto al que la spec quiere evitar. Por eso, si difiere, hace falta una
     * confirmación explícita (FR-014).
     */
    public function aprobar(Request $request, MercadoLibreRetencionPrecio $retencion): JsonResponse
    {
        if (! $retencion->estaAbierta()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esa retención ya fue resuelta. Actualizá la lista.',
            ], 409);
        }

        $configuracion = MercadoLibreConfiguracion::actual();
        $vigente = $this->precioVigente($retencion, $configuracion);

        if ($vigente === null) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'El producto ya no tiene precio en esa lista. Cargalo antes de aprobar.',
            ], 422);
        }

        if (abs($vigente - (float) $retencion->precio_propuesto) > 0.005 && ! $request->boolean('confirma_precio_distinto')) {
            return response()->json([
                'ok' => false,
                'requiere_confirmacion' => true,
                'mensaje' => 'El precio de la lista cambió desde que se retuvo. Confirmá antes de publicar.',
                'precio_propuesto' => (float) $retencion->precio_propuesto,
                'precio_vigente_lista' => $vigente,
            ], 422);
        }

        $publicacion = $retencion->publicacion;

        // Se cierra ANTES de enviar: si siguiera abierta, el corte volvería a evaluar el mismo
        // precio y lo retendría otra vez — la aprobación no podría ejecutarse nunca.
        $retencion->update([
            'estado' => MercadoLibreRetencionPrecio::ESTADO_APROBADA,
            'resuelta_en' => now(),
            'resuelta_por_id' => auth()->id(),
            'precio_enviado' => $vigente,
        ]);

        $enviado = $this->sincronizador->enviarUno($publicacion, $vigente, omitirCorte: true);

        if (! $enviado) {
            // Mercado Libre rechazó el envío: la retención vuelve a abrirse. No se da por resuelto
            // algo que no se pudo publicar (contracts §2).
            $retencion->update([
                'estado' => MercadoLibreRetencionPrecio::ESTADO_ABIERTA,
                'resuelta_en' => null,
                'resuelta_por_id' => null,
                'precio_enviado' => null,
            ]);

            return response()->json([
                'ok' => false,
                'mensaje' => $publicacion->fresh()->precio_error ?? 'Mercado Libre rechazó la actualización.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Precio publicado en Mercado Libre.',
            'precio_enviado' => $vigente,
            'retencion' => ['id' => $retencion->id, 'estado' => MercadoLibreRetencionPrecio::ESTADO_APROBADA],
        ]);
    }

    /** Rechazar: cierra sin enviar nada (FR-013). El precio en Mercado Libre no cambia. */
    public function rechazar(MercadoLibreRetencionPrecio $retencion): JsonResponse
    {
        if (! $retencion->estaAbierta()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esa retención ya fue resuelta. Actualizá la lista.',
            ], 409);
        }

        $retencion->update([
            'estado' => MercadoLibreRetencionPrecio::ESTADO_RECHAZADA,
            'resuelta_en' => now(),
            'resuelta_por_id' => auth()->id(),
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Retención descartada. El precio en Mercado Libre no cambió.',
            'retencion' => ['id' => $retencion->id, 'estado' => MercadoLibreRetencionPrecio::ESTADO_RECHAZADA],
        ]);
    }

    /**
     * Precio que se enviaría hoy: el de la lista que le corresponde a esa publicación **por tipo**.
     * Se delega en `resolverListaPrecio()`, que es la única definición del proyecto — duplicarla
     * fue la causa del incidente del 25/08.
     */
    private function precioVigente(MercadoLibreRetencionPrecio $retencion, MercadoLibreConfiguracion $configuracion): ?float
    {
        $publicacion = $retencion->publicacion;

        if (! $publicacion?->producto) {
            return null;
        }

        $lista = $this->sincronizador->resolverListaPrecio($publicacion, $configuracion);

        if (! $lista) {
            return null;
        }

        $precio = DB::table('precios_producto')
            ->where('producto_id', $publicacion->producto_id)
            ->where('lista_precio_id', $lista)
            ->value('precio');

        return $precio === null ? null : (float) $precio;
    }
}
