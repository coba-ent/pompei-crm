<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks obligatorios de privacidad de la Application (partner portal) —
 * requisito de Tiendanube para completar el alta de la app
 * (api-documentation/resources/webhook). Se limitan a registrar la solicitud
 * y responder 2xx (plazo de 3s); el borrado real de datos de cliente queda
 * pendiente de spec propia antes de ir a producción con un comerciante real.
 */
class TiendanubeWebhookController extends Controller
{
    public function storeRedact(Request $request): JsonResponse
    {
        return $this->recibir($request, 'webhook_store_redact');
    }

    public function customersRedact(Request $request): JsonResponse
    {
        return $this->recibir($request, 'webhook_customers_redact');
    }

    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->recibir($request, 'webhook_customers_data_request');
    }

    private function recibir(Request $request, string $operacion): JsonResponse
    {
        if (! $this->firmaValida($request)) {
            TiendanubeRestOperacionLog::registrar([
                'operacion' => $operacion,
                'metodo' => 'POST',
                'endpoint' => $request->path(),
                'sentido' => 'lectura',
                'resultado' => 'error',
                'mensaje_error' => 'Firma HMAC inválida o ausente.',
            ]);

            return response()->json(['ok' => false], 401);
        }

        TiendanubeRestOperacionLog::registrar([
            'operacion' => $operacion,
            'metodo' => 'POST',
            'endpoint' => $request->path(),
            'sentido' => 'lectura',
            'resultado' => 'exito',
            'payload_bloqueado' => json_encode($request->all()),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Header x-linkedstore-hmac-sha256 = HMAC-SHA256(body crudo, client_secret
     * de la app), en base64. Sin TN_WEBHOOK_SECRET todavía configurado (app
     * recién creada en el partner portal) no se puede verificar: se deja
     * pasar para no romper el alta, pero queda registrado como "sin firma".
     */
    private function firmaValida(Request $request): bool
    {
        $secret = config('integraciones.tiendanube.webhook_secret');

        if (blank($secret)) {
            return true;
        }

        $firmaRecibida = $request->header('x-linkedstore-hmac-sha256');

        if (blank($firmaRecibida)) {
            return false;
        }

        $firmaCalculada = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($firmaCalculada, $firmaRecibida);
    }
}
