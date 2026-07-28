<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Services\MercadoLibre\Excepciones\ConexionCaidaException;
use App\Services\MercadoLibre\Excepciones\CredencialesIlegiblesException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Punto ÚNICO de salida hacia la API de Mercado Libre (plan.md "Enfoque
 * técnico"). Todo lo que puede salir mal en esta integración se resuelve acá
 * y sólo acá: renovación perezosa del token bajo lock atómico (R4), kill-switch
 * de escrituras (R7), y registro de cada operación en el historial (R9).
 *
 * Ningún otro servicio debe llamar a la API de Mercado Libre directamente
 * (salvo VinculacionMercadoLibre::canjearCodigo(), que por definición corre
 * ANTES de que exista una cuenta autenticada).
 */
class ClienteMercadoLibre
{
    private const API_BASE = 'https://api.mercadolibre.com';

    private const ESPERAS_SEGUNDOS = [1, 2, 4];

    private const MAX_INTENTOS_TRANSITORIOS = 3;

    public function obtener(string $operacion, string $endpoint, array $opciones = []): RespuestaMercadoLibre
    {
        return $this->peticion($operacion, 'GET', $endpoint, $opciones);
    }

    public function enviar(string $operacion, string $metodo, string $endpoint, array $opciones = []): RespuestaMercadoLibre
    {
        return $this->peticion($operacion, $metodo, $endpoint, $opciones);
    }

    /**
     * @param  array  $opciones  Query params (GET) o cuerpo JSON (otros métodos). La clave
     *                           `omitir_guard_funcion` (bool) exime del guard FR-005b — sólo la usan
     *                           el flujo de vinculación y "Probar conexión" (T051a).
     */
    public function peticion(string $operacion, string $metodo, string $endpoint, array $opciones = []): RespuestaMercadoLibre
    {
        $omitirGuardFuncion = (bool) ($opciones['omitir_guard_funcion'] ?? false);
        unset($opciones['omitir_guard_funcion']);

        $metodo = strtoupper($metodo);
        $sentido = $metodo === 'GET' ? 'lectura' : 'escritura';

        // FR-005b: con la función "mercadolibre" desactivada, toda operación se
        // bloquea (lectura y escritura) salvo el propio flujo de reconfiguración.
        if (! $omitirGuardFuncion && ! $this->funcionMercadoLibreActiva()) {
            return $this->registrarBloqueada(
                $operacion, $metodo, $endpoint, $sentido, $opciones,
                'La función "Mercado Libre" está desactivada en Funciones Avanzadas.'
            );
        }

        // R7/SC-005: kill-switch de escrituras, verificado en este único punto.
        if ($sentido === 'escritura' && MercadoLibreConfiguracion::actual()->modo_solo_lectura) {
            return $this->registrarBloqueada(
                $operacion, $metodo, $endpoint, $sentido, $opciones,
                'Bloqueada por el modo sólo lectura: las escrituras hacia Mercado Libre están deshabilitadas.'
            );
        }

        $cuenta = MercadoLibreCuenta::conectada()->first();

        if (! $cuenta) {
            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, 'No hay ninguna cuenta de Mercado Libre conectada.');
        }

        try {
            $cuenta = $this->asegurarTokenVigente($cuenta);
        } catch (ConexionCaidaException|CredencialesIlegiblesException $e) {
            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, $e->getMessage());
        }

        return $this->ejecutarConReintentos($operacion, $metodo, $endpoint, $sentido, $opciones, $cuenta, renovadoYa: false);
    }

    private function ejecutarConReintentos(
        string $operacion,
        string $metodo,
        string $endpoint,
        string $sentido,
        array $opciones,
        MercadoLibreCuenta $cuenta,
        bool $renovadoYa,
    ): RespuestaMercadoLibre {
        $intentosTransitorios = 0;

        while (true) {
            $inicio = microtime(true);

            try {
                $respuestaHttp = Http::timeout(30)->connectTimeout(10)
                    ->withToken($cuenta->access_token)
                    ->acceptJson()
                    ->send($metodo, self::API_BASE.$endpoint, $metodo === 'GET' ? ['query' => $opciones] : ['json' => $opciones]);
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, 'No se pudo conectar con Mercado Libre.', $this->duracionMs($inicio));
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $duracionMs = $this->duracionMs($inicio);
            $codigo = $respuestaHttp->status();

            if ($respuestaHttp->successful()) {
                $this->registrarExito($operacion, $metodo, $endpoint, $sentido, $codigo, $duracionMs);

                return RespuestaMercadoLibre::ok($respuestaHttp->json() ?? [], $codigo, $respuestaHttp->headers());
            }

            if ($codigo === 401 && ! $renovadoYa) {
                try {
                    $cuenta = $this->asegurarTokenVigente($cuenta, forzarRenovacion: true);
                } catch (ConexionCaidaException|CredencialesIlegiblesException $e) {
                    return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $e->getMessage(), $duracionMs);
                }

                return $this->ejecutarConReintentos($operacion, $metodo, $endpoint, $sentido, $opciones, $cuenta, renovadoYa: true);
            }

            if ($codigo === 401) {
                $mensaje = 'La autorización expiró. Volvé a conectar la cuenta.';
                $cuenta->update(['estado' => EstadoConexion::Caida->value, 'ultimo_error' => $mensaje]);

                return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $mensaje, $duracionMs);
            }

            // 403: falta de permiso funcional en la app — nunca dispara renovación (R8/T042b).
            if ($codigo === 403) {
                return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $this->mensajePermisoFaltante($respuestaHttp), $duracionMs);
            }

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = $codigo === 429
                        ? 'Mercado Libre limitó las solicitudes. Intentá de nuevo en unos minutos.'
                        : 'Mercado Libre no respondió correctamente. Intentá de nuevo más tarde.';

                    return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $mensaje, $duracionMs);
                }

                $espera = $this->esperaSegundos($intentosTransitorios);
                $retryAfter = $respuestaHttp->header('Retry-After');
                if (is_numeric($retryAfter)) {
                    $espera = max($espera, (int) $retryAfter);
                }

                sleep($espera);

                continue;
            }

            // 400 y el resto: error de validación/config del proveedor, no se reintenta.
            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $this->mensajeErrorGenerico($respuestaHttp), $duracionMs);
        }
    }

    /**
     * Renovación perezosa (R3): sólo si el token está vencido o a 10' de vencer,
     * bajo lock atómico (R4) para que dos procesos no rompan la cadena de
     * refresh_token de un solo uso.
     */
    private function asegurarTokenVigente(MercadoLibreCuenta $cuenta, bool $forzarRenovacion = false): MercadoLibreCuenta
    {
        if (! $forzarRenovacion && ! $cuenta->tokenVencido(10)) {
            return $cuenta;
        }

        return Cache::lock('ml:refresh', 15)->block(20, function () use ($cuenta, $forzarRenovacion) {
            $actual = MercadoLibreCuenta::find($cuenta->id);

            if (! $actual || $actual->estado !== EstadoConexion::Conectada) {
                throw new ConexionCaidaException('La conexión con Mercado Libre ya no está activa.');
            }

            if (! $forzarRenovacion && ! $actual->tokenVencido(10)) {
                return $actual;
            }

            // Otro proceso ya renovó mientras esperábamos el lock: usar ese token, sin
            // gastar otra vez la cadena de refresh_token de un solo uso.
            if ($forzarRenovacion && $actual->access_token !== $cuenta->access_token) {
                return $actual;
            }

            return $this->renovarToken($actual);
        });
    }

    /** FR-028a/FR-029/FR-030/FR-031: renovación bajo lock, con la política de reintentos de 429/5xx. */
    public function renovarToken(MercadoLibreCuenta $cuenta): MercadoLibreCuenta
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        try {
            $refreshToken = $cuenta->refresh_token;
        } catch (DecryptException $e) {
            $mensaje = 'Las credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?).';
            $cuenta->update(['estado' => EstadoConexion::Caida->value, 'ultimo_error' => $mensaje]);

            throw new CredencialesIlegiblesException($mensaje);
        }

        $intentosTransitorios = 0;

        while (true) {
            $inicio = microtime(true);

            try {
                $respuesta = Http::asForm()->timeout(30)->connectTimeout(10)->acceptJson()
                    ->post(self::API_BASE.'/oauth/token', [
                        'grant_type' => 'refresh_token',
                        'client_id' => $configuracion->client_id,
                        'client_secret' => $configuracion->client_secret,
                        'refresh_token' => $refreshToken,
                    ]);
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = 'No se pudo conectar con Mercado Libre para renovar el acceso.';
                    $this->registrarLog('renovar_token', 'POST', '/oauth/token', 'escritura', 'error', null, $this->duracionMs($inicio), $mensaje);

                    throw new ConexionCaidaException($mensaje);
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $duracionMs = $this->duracionMs($inicio);

            if ($respuesta->successful()) {
                $cuerpo = $respuesta->json();

                $cuenta->access_token = $cuerpo['access_token'];
                $cuenta->refresh_token = $cuerpo['refresh_token'];
                $cuenta->token_expira_en = now()->addSeconds((int) $cuerpo['expires_in']);
                $cuenta->ultimo_refresh_en = now();
                $cuenta->estado = EstadoConexion::Conectada->value;
                $cuenta->ultimo_error = null;
                $cuenta->save();

                $this->registrarLog('renovar_token', 'POST', '/oauth/token', 'escritura', 'exito', 200, $duracionMs);

                return $cuenta;
            }

            $codigo = $respuesta->status();

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = 'Mercado Libre no respondió correctamente al renovar el acceso. Se reintentará en la próxima operación.';
                    $this->registrarLog('renovar_token', 'POST', '/oauth/token', 'escritura', 'error', $codigo, $duracionMs, $mensaje);

                    // Falla transitoria: NO se marca la conexión como caída (R8/api-mercadolibre.md §3).
                    throw new ConexionCaidaException($mensaje);
                }

                $espera = $this->esperaSegundos($intentosTransitorios);
                $retryAfter = $respuesta->header('Retry-After');
                if (is_numeric($retryAfter)) {
                    $espera = max($espera, (int) $retryAfter);
                }

                sleep($espera);

                continue;
            }

            // 400 invalid_grant / 401: irrecuperable — no se reintenta, se exige re-autorización (FR-031).
            $mensaje = 'La autorización expiró o fue revocada. Volvé a conectar la cuenta.';
            $cuenta->update(['estado' => EstadoConexion::Caida->value, 'ultimo_error' => $mensaje]);
            $this->registrarLog('renovar_token', 'POST', '/oauth/token', 'escritura', 'error', $codigo, $duracionMs, $mensaje);

            throw new ConexionCaidaException($mensaje);
        }
    }

    private function funcionMercadoLibreActiva(): bool
    {
        return (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa');
    }

    private function mensajePermisoFaltante($respuestaHttp): string
    {
        $cuerpo = $respuestaHttp->json() ?? [];
        $error = $cuerpo['error'] ?? ($cuerpo['message'] ?? '');

        if (str_contains((string) $error, 'POLICIES')) {
            return 'La aplicación no tiene habilitado el permiso funcional necesario para esta operación. Habilitalo en el DevCenter de Mercado Libre.';
        }

        return 'Mercado Libre rechazó la operación por falta de permisos.';
    }

    private function mensajeErrorGenerico($respuestaHttp): string
    {
        $cuerpo = $respuestaHttp->json() ?? [];

        return $cuerpo['message'] ?? $cuerpo['error'] ?? 'Mercado Libre rechazó la operación.';
    }

    private function esperaSegundos(int $intento): int
    {
        return self::ESPERAS_SEGUNDOS[$intento - 1] ?? 4;
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function registrarExito(string $operacion, string $metodo, string $endpoint, string $sentido, int $codigo, int $duracionMs): void
    {
        $this->registrarLog($operacion, $metodo, $endpoint, $sentido, 'exito', $codigo, $duracionMs);
    }

    private function registrarError(string $operacion, string $metodo, string $endpoint, string $sentido, ?int $codigo, string $mensaje, ?int $duracionMs = null): RespuestaMercadoLibre
    {
        $this->registrarLog($operacion, $metodo, $endpoint, $sentido, 'error', $codigo, $duracionMs, $mensaje);

        return RespuestaMercadoLibre::error($mensaje, $codigo);
    }

    private function registrarBloqueada(string $operacion, string $metodo, string $endpoint, string $sentido, array $opciones, string $mensaje): RespuestaMercadoLibre
    {
        $this->registrarLog($operacion, $metodo, $endpoint, $sentido, 'bloqueada', null, null, null, $opciones ? json_encode($opciones) : null);

        return RespuestaMercadoLibre::bloqueada($mensaje);
    }

    private function registrarLog(
        string $operacion,
        string $metodo,
        string $endpoint,
        string $sentido,
        string $resultado,
        ?int $codigoHttp,
        ?int $duracionMs,
        ?string $mensajeError = null,
        ?string $payloadBloqueado = null,
    ): void {
        MercadoLibreOperacionLog::registrar([
            'operacion' => $operacion,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'sentido' => $sentido,
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'payload_bloqueado' => $payloadBloqueado,
            'usuario_id' => auth()->id(),
        ]);
    }
}
