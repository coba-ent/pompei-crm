<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Services\Tiendanube\Excepciones\CredencialesIlegiblesException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Punto ÚNICO de salida hacia la API de Tiendanube (plan.md "Enfoque técnico").
 * Considerablemente más simple que ClienteMercadoLibre: sin renovación de
 * token ni lock de concurrencia (research.md §R11) — el token de una
 * Aplicación personalizada no vence. Resuelve en un solo lugar el kill-switch
 * de escrituras (FR-016) y el registro de toda operación en el historial
 * (FR-020).
 */
class ClienteTiendanube
{
    private const API_BASE = 'https://api.tiendanube.com/v1';

    private const USER_AGENT = 'Contagram CRM (contacto@negocio.com)';

    private const ESPERAS_SEGUNDOS = [1, 2, 4];

    private const MAX_INTENTOS_TRANSITORIOS = 3;

    /** GET /{store_id}/store (research.md §R4): datos de la tienda vinculada. */
    public function probarConexion(array $opciones = []): RespuestaTiendanube
    {
        $configuracion = TiendanubeConfiguracion::actual();

        $respuesta = $this->obtener('probar_conexion', '/'.$configuracion->store_id.'/store', $opciones);

        if ($respuesta->exito) {
            $datos = $respuesta->datos;
            $nombre = $datos['name'] ?? null;

            $configuracion->nombre_tienda = is_array($nombre) ? ($nombre['es'] ?? reset($nombre) ?: null) : $nombre;
            $configuracion->dominio = $datos['original_domain'] ?? $datos['url'] ?? $configuracion->dominio;
            $configuracion->pais = $datos['country'] ?? null;
            $configuracion->moneda = $datos['currency'] ?? null;
            $configuracion->estado = EstadoConexion::Conectada;
            $configuracion->ultimo_error = null;
            $configuracion->ultima_verificacion_en = now();
            $configuracion->save();
        }

        return $respuesta;
    }

    public function obtener(string $operacion, string $endpoint, array $opciones = []): RespuestaTiendanube
    {
        return $this->peticion($operacion, 'GET', $endpoint, $opciones);
    }

    public function enviar(string $operacion, string $metodo, string $endpoint, array $opciones = []): RespuestaTiendanube
    {
        return $this->peticion($operacion, $metodo, $endpoint, $opciones);
    }

    /**
     * @param  array  $opciones  Query params (GET) o cuerpo JSON (otros métodos). La clave
     *                           `omitir_guard_funcion` (bool) exime del guard FR-006b — sólo la usa
     *                           "Probar conexión" disparado desde el guardado de credenciales.
     */
    public function peticion(string $operacion, string $metodo, string $endpoint, array $opciones = []): RespuestaTiendanube
    {
        $omitirGuardFuncion = (bool) ($opciones['omitir_guard_funcion'] ?? false);
        unset($opciones['omitir_guard_funcion']);

        $metodo = strtoupper($metodo);
        $sentido = $metodo === 'GET' ? 'lectura' : 'escritura';

        // FR-006b: con la función "tiendanube" desactivada, toda operación se bloquea
        // (lectura y escritura) salvo el propio flujo de reconfiguración.
        if (! $omitirGuardFuncion && ! $this->funcionTiendanubeActiva()) {
            return $this->registrarBloqueada(
                $operacion, $metodo, $endpoint, $sentido, $opciones,
                'La función "Tiendanube" está desactivada en Funciones Avanzadas.'
            );
        }

        $configuracion = TiendanubeConfiguracion::actual();

        // FR-016: kill-switch de escrituras, verificado en este único punto.
        if ($sentido === 'escritura' && $configuracion->modo_solo_lectura) {
            return $this->registrarBloqueada(
                $operacion, $metodo, $endpoint, $sentido, $opciones,
                'Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.'
            );
        }

        if (! $configuracion->estaCompleta()) {
            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, 'No hay credenciales de Tiendanube cargadas.');
        }

        // T025a: DecryptException al leer access_token (APP_KEY cambió, edge case
        // spec.md) se traduce a CredencialesIlegiblesException, pero peticion() —
        // igual que ClienteMercadoLibre::peticion() con asegurarTokenVigente() — la
        // absorbe acá mismo y siempre devuelve una RespuestaTiendanube, nunca lanza.
        try {
            $token = $configuracion->access_token;
        } catch (DecryptException $e) {
            $mensaje = 'Las credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?).';
            $configuracion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);
            $excepcion = new CredencialesIlegiblesException($mensaje);

            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, $excepcion->getMessage());
        }

        return $this->ejecutarConReintentos($operacion, $metodo, $endpoint, $sentido, $opciones, $token, $configuracion);
    }

    private function ejecutarConReintentos(
        string $operacion,
        string $metodo,
        string $endpoint,
        string $sentido,
        array $opciones,
        string $token,
        TiendanubeConfiguracion $configuracion,
    ): RespuestaTiendanube {
        $intentosTransitorios = 0;

        while (true) {
            $inicio = microtime(true);

            try {
                $respuestaHttp = Http::timeout(30)->connectTimeout(10)
                    ->withHeaders([
                        // ⚠️ `Authentication`, no `Authorization` (research.md §R3) — trampa de la API.
                        'Authentication' => 'bearer '.$token,
                        'User-Agent' => self::USER_AGENT,
                    ])
                    ->acceptJson()
                    ->send($metodo, self::API_BASE.$endpoint, $metodo === 'GET' ? ['query' => $opciones] : ['json' => $opciones]);
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    return $this->registrarError($operacion, $metodo, $endpoint, $sentido, null, 'No se pudo conectar con Tiendanube.', $this->duracionMs($inicio));
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $duracionMs = $this->duracionMs($inicio);
            $codigo = $respuestaHttp->status();

            if ($respuestaHttp->successful()) {
                $this->registrarExito($operacion, $metodo, $endpoint, $sentido, $codigo, $duracionMs);

                return RespuestaTiendanube::ok($respuestaHttp->json() ?? [], $codigo, $respuestaHttp->headers());
            }

            // 401/403: credencial inválida o revocada. 404: el store_id no corresponde
            // al token (edge case spec.md) — mismo tratamiento (research.md §R5).
            if ($codigo === 401 || $codigo === 403 || $codigo === 404) {
                $mensaje = $codigo === 404
                    ? 'El identificador de tienda no corresponde al token cargado. Verificá los datos.'
                    : 'La credencial fue rechazada por Tiendanube. Volvé a cargar el token.';

                $configuracion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);

                return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $mensaje, $duracionMs);
            }

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = $codigo === 429
                        ? 'Tiendanube limitó las solicitudes. Intentá de nuevo en unos minutos.'
                        : 'Tiendanube no respondió correctamente. Intentá de nuevo más tarde.';

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

            // 400/422 y el resto: error de validación/config del proveedor, no se reintenta.
            return $this->registrarError($operacion, $metodo, $endpoint, $sentido, $codigo, $this->mensajeErrorGenerico($respuestaHttp), $duracionMs);
        }
    }

    private function funcionTiendanubeActiva(): bool
    {
        return (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa');
    }

    private function mensajeErrorGenerico($respuestaHttp): string
    {
        $cuerpo = $respuestaHttp->json() ?? [];

        return $cuerpo['message'] ?? $cuerpo['error'] ?? 'Tiendanube rechazó la operación.';
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

    private function registrarError(string $operacion, string $metodo, string $endpoint, string $sentido, ?int $codigo, string $mensaje, ?int $duracionMs = null): RespuestaTiendanube
    {
        $this->registrarLog($operacion, $metodo, $endpoint, $sentido, 'error', $codigo, $duracionMs, $mensaje);

        return RespuestaTiendanube::error($mensaje, $codigo);
    }

    private function registrarBloqueada(string $operacion, string $metodo, string $endpoint, string $sentido, array $opciones, string $mensaje): RespuestaTiendanube
    {
        $this->registrarLog($operacion, $metodo, $endpoint, $sentido, 'bloqueada', null, null, null, $opciones ? json_encode($opciones) : null);

        return RespuestaTiendanube::bloqueada($mensaje);
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
        TiendanubeOperacionLog::registrar([
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
