<?php

namespace App\Services\Tiendanube;

use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Services\Tiendanube\Excepciones\RegistroClienteFallidoException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Auto-registro del cliente OAuth contra admin-mcp.tiendanube.com (Dynamic
 * Client Registration, RFC 7591 — research.md §R1). Se hace una única vez: si
 * ya hay un client_id/client_secret guardados y legibles, se reutilizan.
 */
class RegistradorClienteOAuth
{
    private const REGISTER_URL = 'https://admin-mcp.tiendanube.com/register';

    public function registrarSiHaceFalta(): TiendanubeConfiguracion
    {
        $configuracion = TiendanubeConfiguracion::actual();

        if ($this->clienteYaRegistradoYLegible($configuracion)) {
            return $configuracion;
        }

        $inicio = microtime(true);

        try {
            $respuesta = Http::timeout(30)->connectTimeout(10)->acceptJson()
                ->post(self::REGISTER_URL, [
                    'redirect_uris' => [route('configuracion.tiendanube.callback')],
                    'client_name' => 'Contagram CRM',
                    'grant_types' => ['authorization_code', 'refresh_token'],
                    'response_types' => ['code'],
                    'token_endpoint_auth_method' => 'client_secret_post',
                ]);
        } catch (ConnectionException $e) {
            $this->log('error', null, $this->duracionMs($inicio), 'No se pudo conectar con Tiendanube para registrar la aplicación.');

            throw new RegistroClienteFallidoException('No se pudo conectar con Tiendanube para registrar la aplicación. Intentá de nuevo en unos minutos.');
        }

        $duracionMs = $this->duracionMs($inicio);

        if (! $respuesta->successful()) {
            $this->log('error', $respuesta->status(), $duracionMs, 'Tiendanube rechazó el registro de la aplicación.');

            throw new RegistroClienteFallidoException('No se pudo registrar la aplicación contra Tiendanube. Intentá de nuevo en unos minutos.');
        }

        $datos = $respuesta->json() ?? [];

        if (blank($datos['client_id'] ?? null) || blank($datos['client_secret'] ?? null)) {
            $this->log('error', $respuesta->status(), $duracionMs, 'Tiendanube no devolvió las credenciales del cliente OAuth.');

            throw new RegistroClienteFallidoException('Tiendanube no devolvió las credenciales necesarias para registrar la aplicación.');
        }

        $configuracion->client_id = $datos['client_id'];
        $configuracion->client_secret = $datos['client_secret'];
        $configuracion->save();

        $this->log('exito', $respuesta->status(), $duracionMs);

        return $configuracion;
    }

    private function clienteYaRegistradoYLegible(TiendanubeConfiguracion $configuracion): bool
    {
        if (blank($configuracion->getRawOriginal('client_id')) || blank($configuracion->getRawOriginal('client_secret'))) {
            return false;
        }

        try {
            return filled($configuracion->client_secret);
        } catch (DecryptException $e) {
            // Edge case spec.md: el client_secret quedó ilegible (cambió APP_KEY) —
            // no hay forma de reconectar con el cliente existente, hay que
            // registrar uno nuevo en vez de fallar de forma opaca.
            return false;
        }
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function log(string $resultado, ?int $codigoHttp, ?int $duracionMs, ?string $mensajeError = null): void
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'registrar_cliente_oauth',
            'metodo' => 'POST',
            'endpoint' => '/register',
            'sentido' => 'escritura',
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'usuario_id' => auth()->id(),
        ]);
    }
}
