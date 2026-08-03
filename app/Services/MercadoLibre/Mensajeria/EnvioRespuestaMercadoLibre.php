<?php

namespace App\Services\MercadoLibre\Mensajeria;

use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreRespuestaEnviada;
use App\Models\Integraciones\MercadoLibreSugerencia;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use App\Services\MercadoLibre\RespuestaMercadoLibre;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Envía la respuesta real a Mercado Libre vía `ClienteMercadoLibre` (único
 * punto de salida, R5 de research.md) y audita el resultado (FR-006/FR-008).
 * El guard de doble respuesta (FR-007) se apoya en el índice único de
 * `ml_respuestas_enviadas` — la verificación previa evita el viaje de red
 * innecesario, pero la garantía real es la constraint de base de datos.
 */
class EnvioRespuestaMercadoLibre
{
    public function __construct(private readonly ClienteMercadoLibre $cliente) {}

    /** @return array{ok: bool, status: int, message?: string} */
    public function enviar(MercadoLibreConversacion $conversacion, string $texto, int $usuarioId, ?int $sugerenciaId = null): array
    {
        // reorder() es necesario: la relación mensajes() ya trae orderBy('enviado_en') ascendente
        // (spec 032) — encadenar latest() sin reorder() apila un segundo ORDER BY sobre la misma
        // columna que MySQL no usa para desempatar el primero, devolviendo el más antiguo en vez
        // del más reciente (bug real detectado al escribir los tests de spec 033, T022).
        $mensaje = $conversacion->mensajes()->where('origen', 'comprador')->reorder('enviado_en', 'desc')->first();

        if (! $mensaje instanceof MercadoLibreMensaje) {
            return ['ok' => false, 'status' => 422, 'message' => 'La conversación no tiene ningún mensaje del comprador para responder.'];
        }

        if ($mensaje->respuestaExitosa()->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'Esta conversación ya fue respondida.'];
        }

        // Spec 033, US3 (FR-010): sólo se audita la sugerencia si corresponde al mismo mensaje
        // que efectivamente se está respondiendo — si llegó un mensaje nuevo del comprador entre
        // que se generó la sugerencia y se confirmó el envío, no se la asocia (gap detectado al
        // revisar el código real de este método, research.md R4).
        $sugerencia = $sugerenciaId ? MercadoLibreSugerencia::find($sugerenciaId) : null;
        if ($sugerencia && $sugerencia->ml_mensaje_id !== $mensaje->id) {
            $sugerencia = null;
        }

        $respuestaMl = $conversacion->tipo === 'pregunta'
            ? $this->cliente->enviar('mensajeria_responder_pregunta', 'POST', '/answers', [
                'question_id' => (int) $mensaje->ml_id,
                'text' => $texto,
            ])
            : $this->enviarPostVenta($conversacion, $texto);

        $resultado = $respuestaMl->exito ? 'exito' : 'error';

        try {
            DB::transaction(function () use ($mensaje, $texto, $usuarioId, $resultado, $respuestaMl, $conversacion, $sugerencia) {
                MercadoLibreRespuestaEnviada::create([
                    'ml_mensaje_id' => $mensaje->id,
                    'texto_enviado' => $texto,
                    'usuario_id' => $usuarioId,
                    'enviado_en' => now(),
                    'resultado' => $resultado,
                    'error_mensaje' => $respuestaMl->exito ? null : $respuestaMl->mensajeError,
                    'ml_sugerencia_id' => $sugerencia?->id,
                    'sugerencia_editada' => $sugerencia ? $texto !== $sugerencia->texto_sugerido : null,
                ]);

                if ($resultado === 'exito') {
                    // Sin esto la respuesta queda auditada pero nunca aparece en el historial
                    // del chat (bug real detectado 02/08/2026: el envío "funcionaba" pero la
                    // conversación no mostraba el mensaje propio enviado).
                    $enviadoEn = now();

                    MercadoLibreMensaje::create([
                        'ml_conversacion_id' => $conversacion->id,
                        'ml_id' => (string) ($respuestaMl->datos['id'] ?? 'resp-'.Str::uuid()),
                        'origen' => 'negocio',
                        'texto' => $texto,
                        'enviado_en' => $enviadoEn,
                    ]);

                    $conversacion->update(['estado' => 'respondida', 'ultimo_mensaje_en' => $enviadoEn]);
                }
            });
        } catch (QueryException $e) {
            // Colisión de carrera contra el índice único (dos usuarios respondiendo a la
            // vez) — FR-007: la que llega primero gana, la otra se rechaza igual.
            return ['ok' => false, 'status' => 422, 'message' => 'Esta conversación ya fue respondida.'];
        }

        if (! $respuestaMl->exito) {
            return ['ok' => false, 'status' => 422, 'message' => $respuestaMl->mensajeError ?? 'Mercado Libre rechazó el envío.'];
        }

        return ['ok' => true, 'status' => 200];
    }

    /**
     * `POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale` (confirmado 02/08/2026
     * contra developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta). `pack_id` puede ser el
     * `order_id` si no hay pack real (documentado explícitamente). `from` es SIEMPRE el vendedor
     * (la cuenta conectada), `to` el comprador — invertirlos hace que ML rechace el envío.
     *
     * `pack_id_ml` (el dato crudo de ML, siempre presente) — no `orden?->ml_order_id` (esa FK
     * puede ser NULL si la orden todavía no se sincronizó al CRM, lo que armaba
     * `/messages/packs//sellers/...` → 404 de ML; bug real detectado 02/08/2026).
     */
    private function enviarPostVenta(MercadoLibreConversacion $conversacion, string $texto): RespuestaMercadoLibre
    {
        $packId = (string) ($conversacion->pack_id_ml ?? '');
        $sellerId = (string) (MercadoLibreCuenta::conectada()->value('ml_user_id') ?? '');

        return $this->cliente->enviar('mensajeria_responder_post_venta', 'POST', "/messages/packs/{$packId}/sellers/{$sellerId}?tag=post_sale", [
            'from' => ['user_id' => $sellerId],
            'to' => ['user_id' => $conversacion->comprador_ml_id],
            'text' => $texto,
        ]);
    }
}
