<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Jobs\GenerarSugerenciaMercadoLibre;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Services\MercadoLibre\Mensajeria\RecepcionMensajeMercadoLibre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recibe notificaciones de Mercado Libre (topics `questions`/`messages`,
 * spec 032, contracts/webhook-mercadolibre.md). Sin middleware de sesión,
 * responde rápido y de forma idempotente ante reintentos (FR-004).
 */
class MercadoLibreMensajeriaWebhookController extends Controller
{
    public function __construct(private readonly RecepcionMensajeMercadoLibre $recepcion) {}

    public function recibir(Request $request): JsonResponse
    {
        $applicationId = (string) $request->input('application_id');
        $clientId = (string) MercadoLibreConfiguracion::actual()->client_id;

        if ($clientId === '' || $applicationId !== $clientId) {
            return response()->json(['ok' => false], 401);
        }

        // Auditoría del payload crudo (independiente de si el procesamiento tiene éxito):
        // el shape exacto de topic=messages sigue sin confirmarse (T008, contracts/webhook-mercadolibre.md)
        // — sin esto no hay forma de ver qué mandó ML realmente cuando algo falla.
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'webhook_recibido',
            'metodo' => 'POST',
            'endpoint' => 'webhooks/mercadolibre',
            'sentido' => 'lectura',
            'resultado' => 'exito',
            'payload_bloqueado' => json_encode($request->all()),
        ]);

        $mensajesNuevos = $this->recepcion->procesar($request->all());

        // Spec 033, US2 (FR-004/FR-005): sólo mensajes del comprador disparan sugerencia — un
        // mensaje propio notificado por ML (enviado desde otro lado) no necesita borrador. El
        // dispatch() sólo encola, no bloquea la respuesta del webhook.
        if (FuncionAvanzada::where('clave', 'mercadolibre_bot')->value('activa')) {
            foreach ($mensajesNuevos as $mensaje) {
                if ($mensaje->origen === 'comprador') {
                    GenerarSugerenciaMercadoLibre::dispatch($mensaje);
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
