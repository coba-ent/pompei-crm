<?php

namespace App\Services\Tiendanube;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Única responsabilidad: verificar que un access_token de la Application REST
 * del Partner Portal (spec 022) funciona de verdad, mediante GET /{store_id}/store
 * (contracts/api-tiendanube-rest.md §4, FR-005). NO es un cliente REST completo
 * de Tiendanube — eso queda para una spec futura que migre specs 017/018. No
 * importa ni depende de ClienteTiendanube (research.md R5): constantes de
 * reintento propias, para no acoplar esta conexión con la conexión MCP.
 */
class VerificadorConexionRest
{
    private const BASE_URL = 'https://api.tiendanube.com/v1';

    private const USER_AGENT = 'Contagram CRM (contacto@contagram.com.ar)';

    private const ESPERAS_SEGUNDOS = [1, 2, 4];

    private const MAX_INTENTOS_TRANSITORIOS = 3;

    /**
     * @return array{ok: bool, nombre: ?string, dominio: ?string, codigo_http: ?int, mensaje_error: ?string}
     */
    public function verificar(string $accessToken, string $storeId): array
    {
        $intentosTransitorios = 0;

        while (true) {
            try {
                $respuesta = Http::timeout(30)->connectTimeout(10)
                    ->withHeaders([
                        'Authentication' => 'bearer '.$accessToken,
                        'User-Agent' => self::USER_AGENT,
                    ])
                    ->get(self::BASE_URL.'/'.$storeId.'/store');
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    return $this->error(null, 'No se pudo conectar con Tiendanube.');
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $codigo = $respuesta->status();

            if ($codigo === 401 || $codigo === 404) {
                return $this->error($codigo, 'La credencial fue rechazada por Tiendanube. Volvé a conectar.');
            }

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = $codigo === 429
                        ? 'Tiendanube limitó las solicitudes. Intentá de nuevo en unos minutos.'
                        : 'El servidor de Tiendanube no respondió correctamente. Intentá de nuevo más tarde.';

                    return $this->error($codigo, $mensaje);
                }

                $espera = $this->esperaSegundos($intentosTransitorios);
                $retryAfter = $respuesta->header('Retry-After');
                if (is_numeric($retryAfter)) {
                    $espera = max($espera, (int) $retryAfter);
                }

                sleep($espera);

                continue;
            }

            if (! $respuesta->successful()) {
                return $this->error($codigo, 'Tiendanube rechazó la operación.');
            }

            $nombre = $respuesta->json('name.es') ?? $respuesta->json('name');
            $dominio = $respuesta->json('original_domain');

            return [
                'ok' => true,
                'nombre' => is_string($nombre) ? $nombre : null,
                'dominio' => $dominio,
                'codigo_http' => $codigo,
                'mensaje_error' => null,
            ];
        }
    }

    private function error(?int $codigo, string $mensaje): array
    {
        return [
            'ok' => false,
            'nombre' => null,
            'dominio' => null,
            'codigo_http' => $codigo,
            'mensaje_error' => $mensaje,
        ];
    }

    private function esperaSegundos(int $intento): int
    {
        return self::ESPERAS_SEGUNDOS[$intento - 1] ?? 4;
    }
}
