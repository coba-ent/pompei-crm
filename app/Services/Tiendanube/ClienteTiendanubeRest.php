<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente REST completo de Tiendanube (spec 024, generalización de
 * `VerificadorConexionRest` de spec 022), reemplazando a `ClienteTiendanube`
 * (MCP) como dependencia de negocio de los sincronizadores y de
 * `VinculadorAutomatico`. Habla contra `api.tiendanube.com` usando la
 * conexión ya validada de `tn_conexion_rest`. Mismo esquema de
 * reintentos/backoff y mismos headers que `VerificadorConexionRest`
 * (plan.md §"Enfoque técnico" 1), pero parametrizado por verbo y ruta.
 */
class ClienteTiendanubeRest
{
    private const BASE_URL = 'https://api.tiendanube.com/v1';

    private const USER_AGENT = 'Contagram CRM (contacto@contagram.com.ar)';

    private const ESPERAS_SEGUNDOS = [1, 2, 4];

    private const MAX_INTENTOS_TRANSITORIOS = 3;

    /** Operación de lectura: `GET /{store_id}/{recurso}`. */
    public function leer(string $recurso, array $query = []): RespuestaTiendanube
    {
        return $this->peticion('GET', $recurso, $query);
    }

    /**
     * Operación de escritura: `POST`/`PUT /{store_id}/{recurso}`, sujeta al
     * guard de función avanzada activa y al kill-switch de modo sólo lectura
     * (FR-017 de spec 024, hereda FR-012 de spec 015/019).
     */
    public function escribir(string $metodo, string $recurso, array $payload = []): RespuestaTiendanube
    {
        if (! $this->funcionTiendanubeActiva()) {
            return $this->registrarBloqueada(
                strtoupper($metodo), $recurso, 'escritura',
                'La función "Tiendanube" está desactivada en Funciones Avanzadas.'
            );
        }

        $conexion = TiendanubeConexionRest::actual();

        if ($conexion->modo_solo_lectura) {
            return $this->registrarBloqueada(
                strtoupper($metodo), $recurso, 'escritura',
                'Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.'
            );
        }

        return $this->peticion($metodo, $recurso, $payload);
    }

    private function peticion(string $metodo, string $recurso, array $datos): RespuestaTiendanube
    {
        $sentido = strtoupper($metodo) === 'GET' ? 'lectura' : 'escritura';
        $conexion = TiendanubeConexionRest::actual();

        if (! $conexion->estaCompleta()) {
            return $this->registrarError(strtoupper($metodo), $recurso, $sentido, null, 'No hay una conexión con Tiendanube establecida.');
        }

        try {
            $token = $conexion->access_token;
        } catch (DecryptException $e) {
            $mensaje = 'Las credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?).';
            $conexion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);

            return $this->registrarError(strtoupper($metodo), $recurso, $sentido, null, $mensaje);
        }

        return $this->ejecutarConReintentos($metodo, $recurso, $datos, $sentido, $token, $conexion);
    }

    private function ejecutarConReintentos(
        string $metodo,
        string $recurso,
        array $datos,
        string $sentido,
        string $token,
        TiendanubeConexionRest $conexion,
    ): RespuestaTiendanube {
        $metodoHttp = strtolower($metodo);
        $url = self::BASE_URL.'/'.$conexion->store_id.'/'.ltrim($recurso, '/');
        $intentosTransitorios = 0;

        while (true) {
            $inicio = microtime(true);

            try {
                $peticionHttp = Http::timeout(30)->connectTimeout(10)
                    ->withHeaders([
                        'Authentication' => 'bearer '.$token,
                        'User-Agent' => self::USER_AGENT,
                    ]);

                $respuestaHttp = $metodoHttp === 'get'
                    ? $peticionHttp->get($url, $datos)
                    : $peticionHttp->{$metodoHttp}($url, $datos);
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    return $this->registrarError($metodo, $recurso, $sentido, null, 'No se pudo conectar con Tiendanube.', $this->duracionMs($inicio));
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $duracionMs = $this->duracionMs($inicio);
            $codigo = $respuestaHttp->status();

            if ($codigo === 401 || $codigo === 404) {
                $mensaje = 'La credencial fue rechazada por Tiendanube. Volvé a conectar.';
                $conexion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);

                return $this->registrarError($metodo, $recurso, $sentido, $codigo, $mensaje, $duracionMs);
            }

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = $codigo === 429
                        ? 'Tiendanube limitó las solicitudes. Intentá de nuevo en unos minutos.'
                        : 'El servidor de Tiendanube no respondió correctamente. Intentá de nuevo más tarde.';

                    return $this->registrarError($metodo, $recurso, $sentido, $codigo, $mensaje, $duracionMs);
                }

                $espera = $this->esperaSegundos($intentosTransitorios);
                $retryAfter = $respuestaHttp->header('Retry-After');
                if (is_numeric($retryAfter)) {
                    $espera = max($espera, (int) $retryAfter);
                }

                sleep($espera);

                continue;
            }

            if (! $respuestaHttp->successful()) {
                $mensaje = $respuestaHttp->json('message') ?? $respuestaHttp->json('error') ?? 'Tiendanube rechazó la operación.';

                return $this->registrarError($metodo, $recurso, $sentido, $codigo, $mensaje, $duracionMs);
            }

            $this->registrarExito($metodo, $recurso, $sentido, $codigo, $duracionMs);

            return RespuestaTiendanube::ok($respuestaHttp->json() ?? [], $codigo, $respuestaHttp->headers());
        }
    }

    private function funcionTiendanubeActiva(): bool
    {
        return (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa');
    }

    private function esperaSegundos(int $intento): int
    {
        return self::ESPERAS_SEGUNDOS[$intento - 1] ?? 4;
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function registrarExito(string $metodo, string $recurso, string $sentido, int $codigo, int $duracionMs): void
    {
        $this->registrarLog($metodo, $recurso, $sentido, 'exito', $codigo, $duracionMs);
    }

    private function registrarError(string $metodo, string $recurso, string $sentido, ?int $codigo, string $mensaje, ?int $duracionMs = null): RespuestaTiendanube
    {
        $this->registrarLog($metodo, $recurso, $sentido, 'error', $codigo, $duracionMs, $mensaje);

        return RespuestaTiendanube::error($mensaje, $codigo);
    }

    private function registrarBloqueada(string $metodo, string $recurso, string $sentido, string $mensaje): RespuestaTiendanube
    {
        $this->registrarLog($metodo, $recurso, $sentido, 'bloqueada', null, null, null);

        return RespuestaTiendanube::bloqueada($mensaje);
    }

    private function registrarLog(
        string $metodo,
        string $recurso,
        string $sentido,
        string $resultado,
        ?int $codigoHttp,
        ?int $duracionMs,
        ?string $mensajeError = null,
    ): void {
        TiendanubeRestOperacionLog::registrar([
            'operacion' => $recurso,
            'metodo' => strtoupper($metodo),
            'endpoint' => '/'.ltrim($recurso, '/'),
            'sentido' => $sentido,
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'usuario_id' => auth()->id(),
        ]);
    }
}
