<?php

namespace App\Services\MercadoLibre\Mensajeria;

use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Normaliza una notificación de webhook de Mercado Libre (topic `questions`
 * o `messages`) en `MercadoLibreConversacion`/`MercadoLibreMensaje`, de forma
 * idempotente ante reintentos (FR-004, research.md R4). Único responsable de
 * interpretar el shape de notificación — el controller sólo despacha acá.
 *
 * Shape de `topic=messages` confirmado (02/08/2026) contra
 * developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta: a diferencia de
 * `questions` (donde `resource` es un path tipo `/questions/123`), acá
 * `resource` es directamente el `message_id` — se resuelve con
 * `GET /messages/{message_id}?tag=post_sale`.
 */
class RecepcionMensajeMercadoLibre
{
    public function __construct(private readonly ClienteMercadoLibre $cliente) {}

    /**
     * @return list<MercadoLibreMensaje> los mensajes nuevos creados en esta llamada (spec 033,
     *         R2 de research.md — el controller usa este resultado para despachar
     *         `GenerarSugerenciaMercadoLibre` sin que este servicio conozca esa preocupación).
     */
    public function procesar(array $payload): array
    {
        $topic = $payload['topic'] ?? null;
        $resource = $payload['resource'] ?? null;

        if (! is_string($resource) || $resource === '') {
            return [];
        }

        return match ($topic) {
            'questions' => $this->procesarPregunta($resource),
            'messages' => $this->procesarPostVenta($resource),
            default => [], // Topic no relevante para este módulo — se ignora (contracts §Casos de respuesta).
        };
    }

    /** @return list<MercadoLibreMensaje> */
    private function procesarPregunta(string $resource): array
    {
        if (! str_starts_with($resource, '/')) {
            return []; // `resource` de `questions` sí es un path (confirmado, R1) — si no lo es, no se toca.
        }

        $respuesta = $this->cliente->obtener('mensajeria_pregunta_detalle', $resource);

        if ($respuesta->fallo()) {
            return [];
        }

        $datos = $respuesta->datos;
        $mlId = (string) ($datos['id'] ?? '');

        if ($mlId === '' || MercadoLibreMensaje::where('ml_id', $mlId)->exists()) {
            return []; // Ya procesado (reintento) — FR-004.
        }

        $itemId = (string) ($datos['item_id'] ?? '');
        $compradorId = (string) ($datos['from']['id'] ?? '');
        $publicacion = $itemId !== ''
            ? MercadoLibrePublicacionProducto::where('ml_item_id', $itemId)->first()
            : null;

        $conversacion = MercadoLibreConversacion::firstOrCreate(
            ['tipo' => 'pregunta', 'comprador_ml_id' => $compradorId, 'publicacion_id_ml' => $itemId],
            [
                'ml_publicacion_producto_id' => $publicacion?->id,
                'estado' => $this->estadoParaPregunta($datos['status'] ?? null),
            ]
        );

        $mensaje = $this->crearMensaje($conversacion, $mlId, 'comprador', (string) ($datos['text'] ?? ''), $datos['date_created'] ?? null);

        return $mensaje ? [$mensaje] : [];
    }

    /** @return list<MercadoLibreMensaje> */
    private function procesarPostVenta(string $messageId): array
    {
        $respuesta = $this->cliente->obtener('mensajeria_post_venta_detalle', "/messages/{$messageId}", ['tag' => 'post_sale']);

        if ($respuesta->fallo()) {
            return [];
        }

        $datos = $respuesta->datos;

        // Dos shapes de respuesta documentados: "con header" ({messages: [...]}) y el
        // formato viejo (el propio objeto es el mensaje, con `message_id` en vez de `id`
        // y `text` anidado en `text.plain`). Se normalizan ambos a la misma forma interna.
        $mensajesCrudos = $datos['messages'] ?? (isset($datos['message_id']) ? [$datos] : []);

        $miMlUserId = (string) (MercadoLibreCuenta::conectada()->value('ml_user_id') ?? '');
        $creados = [];

        foreach ($mensajesCrudos as $mensaje) {
            $mlId = (string) ($mensaje['id'] ?? $mensaje['message_id'] ?? '');

            if ($mlId === '' || MercadoLibreMensaje::where('ml_id', $mlId)->exists()) {
                continue; // Ya procesado (reintento) — FR-004.
            }

            $compradorId = $this->compradorId($mensaje, $miMlUserId);
            $packId = $this->resolverPackId($mensaje);
            $orden = $packId ? MercadoLibreOrden::where('ml_order_id', $packId)->first() : null;

            // Clave de deduplicación: `pack_id_ml` (el dato crudo de ML), no `ml_orden_id` —
            // esa FK puede ser NULL para varios packs distintos sin sincronizar todavía, y
            // dedupear por ahí pisaría conversaciones de compradores distintos entre sí.
            $conversacion = MercadoLibreConversacion::firstOrCreate(
                ['tipo' => 'post_venta', 'pack_id_ml' => $packId],
                ['comprador_ml_id' => $compradorId, 'ml_orden_id' => $orden?->id, 'estado' => 'pendiente']
            );

            // La orden puede sincronizarse recién después de que ya existía la conversación
            // (el webhook de mensaje no espera al cron de órdenes) — completar el vínculo si
            // apareció más tarde.
            if ($orden && ! $conversacion->ml_orden_id) {
                $conversacion->update(['ml_orden_id' => $orden->id]);
            }

            $origen = $compradorId !== '' && $compradorId === $miMlUserId ? 'negocio' : 'comprador';
            $texto = is_array($mensaje['text'] ?? null) ? (string) ($mensaje['text']['plain'] ?? '') : (string) ($mensaje['text'] ?? '');
            $fecha = $mensaje['message_date']['created'] ?? $mensaje['date'] ?? null;

            $creado = $this->crearMensaje($conversacion, $mlId, $origen, $texto, $fecha);

            if ($creado) {
                $creados[] = $creado;
            }
        }

        return $creados;
    }

    /** El comprador es quien NO es la cuenta conectada (vendedor) — más confiable que asumir "from". */
    private function compradorId(array $mensaje, string $miMlUserId): string
    {
        $fromId = (string) ($mensaje['from']['user_id'] ?? '');
        $toId = (string) ($mensaje['to']['user_id'] ?? '');

        if ($fromId !== '' && $fromId !== $miMlUserId) {
            return $fromId;
        }

        return $toId;
    }

    /** `message_resources` trae el pack/orden asociado (shape "con header"); `resource_id` en el shape viejo. */
    private function resolverPackId(array $mensaje): ?string
    {
        foreach ($mensaje['message_resources'] ?? [] as $recurso) {
            if (($recurso['name'] ?? null) === 'packs') {
                return (string) $recurso['id'];
            }
        }

        return isset($mensaje['resource_id']) ? (string) $mensaje['resource_id'] : null;
    }

    private function crearMensaje(MercadoLibreConversacion $conversacion, string $mlId, string $origen, string $texto, ?string $fecha): ?MercadoLibreMensaje
    {
        $enviadoEn = $fecha ? Carbon::parse($fecha) : now();

        try {
            $mensaje = MercadoLibreMensaje::create([
                'ml_conversacion_id' => $conversacion->id,
                'ml_id' => $mlId,
                'origen' => $origen,
                'texto' => $texto,
                'enviado_en' => $enviadoEn,
            ]);
        } catch (QueryException $e) {
            return null; // Colisión de reintento casi simultáneo contra el índice único de ml_id — FR-004.
        }

        if ($enviadoEn->greaterThanOrEqualTo($conversacion->ultimo_mensaje_en ?? $enviadoEn)) {
            $conversacion->ultimo_mensaje_en = $enviadoEn;
        }

        if ($origen === 'comprador' && $conversacion->estado !== 'cerrada') {
            $conversacion->estado = 'pendiente';
        }

        $conversacion->save();

        return $mensaje;
    }

    private function estadoParaPregunta(?string $status): string
    {
        return match ($status) {
            'BANNED', 'DISABLED', 'DELETED' => 'cerrada',
            'ANSWERED' => 'respondida',
            default => 'pendiente',
        };
    }
}
