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
 * Punto ÚNICO de salida hacia el servidor MCP oficial de Tiendanube
 * (`admin-mcp.tiendanube.com`), reemplazando el transporte REST de spec 015
 * por JSON-RPC 2.0 sobre HTTP con respuesta SSE de un único evento
 * (research.md §R2, contracts/admin-mcp-tiendanube.md §5). Sin renovación de
 * token ni lock de concurrencia (research.md §R3/§R9). Resuelve en un solo
 * lugar el kill-switch de escrituras (FR-012) y el registro de toda operación
 * en el historial (FR-013).
 */
class ClienteTiendanube
{
    private const MCP_URL = 'https://admin-mcp.tiendanube.com/';

    private const ESPERAS_SEGUNDOS = [1, 2, 4];

    private const MAX_INTENTOS_TRANSITORIOS = 3;

    /** Invoca una tool de lectura del servidor MCP (`tools/call`). */
    public function leer(string $tool, array $argumentos = [], array $opciones = []): RespuestaTiendanube
    {
        return $this->peticion($tool, 'lectura', $argumentos, $opciones);
    }

    /** Invoca una tool de escritura del servidor MCP (sujeta al kill-switch FR-012). */
    public function escribir(string $tool, array $argumentos = [], array $opciones = []): RespuestaTiendanube
    {
        return $this->peticion($tool, 'escritura', $argumentos, $opciones);
    }

    /**
     * @param  array  $opciones  La clave `omitir_guard_funcion` (bool) exime del guard
     *                           FR-006b/spec015 — sólo la usa la verificación FR-003a
     *                           dentro del propio flujo de conexión (TiendanubeOAuthController).
     */
    public function peticion(string $tool, string $sentido, array $argumentos = [], array $opciones = []): RespuestaTiendanube
    {
        $omitirGuardFuncion = (bool) ($opciones['omitir_guard_funcion'] ?? false);

        // FR-006b (heredado de spec 015): con la función "tiendanube" desactivada,
        // toda operación se bloquea (lectura y escritura) salvo el propio flujo de conexión.
        if (! $omitirGuardFuncion && ! $this->funcionTiendanubeActiva()) {
            return $this->registrarBloqueada(
                $tool, $sentido, $argumentos,
                'La función "Tiendanube" está desactivada en Funciones Avanzadas.'
            );
        }

        $configuracion = TiendanubeConfiguracion::actual();

        // FR-012: kill-switch de escrituras, verificado en este único punto.
        if ($sentido === 'escritura' && $configuracion->modo_solo_lectura) {
            return $this->registrarBloqueada(
                $tool, $sentido, $argumentos,
                'Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.'
            );
        }

        if (! $configuracion->estaCompleta()) {
            return $this->registrarError($tool, $sentido, null, 'No hay una conexión con Tiendanube establecida.');
        }

        // DecryptException al leer access_token (APP_KEY cambió, edge case spec.md)
        // se traduce a CredencialesIlegiblesException; peticion() la absorbe acá
        // mismo y siempre devuelve una RespuestaTiendanube, nunca lanza.
        try {
            $token = $configuracion->access_token;
        } catch (DecryptException $e) {
            $mensaje = 'Las credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?).';
            $configuracion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);
            $excepcion = new CredencialesIlegiblesException($mensaje);

            return $this->registrarError($tool, $sentido, null, $excepcion->getMessage());
        }

        return $this->ejecutarConReintentos($tool, $sentido, $argumentos, $token, $configuracion);
    }

    private function ejecutarConReintentos(
        string $tool,
        string $sentido,
        array $argumentos,
        string $token,
        TiendanubeConfiguracion $configuracion,
    ): RespuestaTiendanube {
        $intentosTransitorios = 0;

        while (true) {
            $inicio = microtime(true);

            try {
                $respuestaHttp = Http::timeout(30)->connectTimeout(10)
                    ->withToken($token)
                    ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                    ->post(self::MCP_URL, [
                        'jsonrpc' => '2.0',
                        'id' => random_int(1, PHP_INT_MAX),
                        'method' => 'tools/call',
                        'params' => ['name' => $tool, 'arguments' => $argumentos],
                    ]);
            } catch (ConnectionException $e) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    return $this->registrarError($tool, $sentido, null, 'No se pudo conectar con el servidor de Tiendanube.', $this->duracionMs($inicio));
                }

                sleep($this->esperaSegundos($intentosTransitorios));

                continue;
            }

            $duracionMs = $this->duracionMs($inicio);
            $codigo = $respuestaHttp->status();

            // research.md §R6 / contracts §6: 401 en cualquier llamada = token
            // inválido/expirado/revocado. Sin refresh_token: se marca "Caída"
            // directamente, sin ningún reintento.
            if ($codigo === 401) {
                $mensaje = 'La credencial fue rechazada por Tiendanube. Volvé a conectar.';
                $configuracion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);

                return $this->registrarError($tool, $sentido, $codigo, $mensaje, $duracionMs);
            }

            if ($codigo === 429 || $codigo >= 500) {
                $intentosTransitorios++;

                if ($intentosTransitorios > self::MAX_INTENTOS_TRANSITORIOS) {
                    $mensaje = $codigo === 429
                        ? 'Tiendanube limitó las solicitudes. Intentá de nuevo en unos minutos.'
                        : 'El servidor de Tiendanube no respondió correctamente. Intentá de nuevo más tarde.';

                    return $this->registrarError($tool, $sentido, $codigo, $mensaje, $duracionMs);
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
                return $this->registrarError($tool, $sentido, $codigo, $this->mensajeErrorGenerico($respuestaHttp), $duracionMs);
            }

            $cuerpo = $this->parsearSse($respuestaHttp->body());

            if ($cuerpo === null) {
                return $this->registrarError($tool, $sentido, $codigo, 'Tiendanube devolvió una respuesta que no se pudo interpretar.', $duracionMs);
            }

            if (isset($cuerpo['error'])) {
                // Error a nivel JSON-RPC (distinto de result.isError, research.md §R6).
                $mensaje = $cuerpo['error']['message'] ?? 'Tiendanube rechazó la operación.';

                return $this->registrarError($tool, $sentido, $codigo, $mensaje, $duracionMs);
            }

            $resultado = $cuerpo['result'] ?? [];

            // FR-011/research.md §R6: error a nivel de protocolo MCP (la request HTTP
            // fue 200, pero la tool falló) — no afecta el estado de la conexión.
            if (! empty($resultado['isError'])) {
                $mensaje = $this->extraerMensajeErrorTool($resultado);
                $this->registrarLog($tool, $sentido, 'error', $codigo, $duracionMs, $mensaje);

                return RespuestaTiendanube::error($mensaje, $codigo);
            }

            $this->registrarExito($tool, $sentido, $codigo, $duracionMs);

            return RespuestaTiendanube::ok($resultado['structuredContent'] ?? [], $codigo, $respuestaHttp->headers());
        }
    }

    /**
     * Extrae el JSON de la respuesta del servidor MCP. En producción llega como
     * SSE de un único evento (`event: message\ndata: {...}`, research.md §R2);
     * en tests con `Http::fake()` suele llegar como JSON plano — se acepta
     * cualquiera de los dos formatos.
     */
    private function parsearSse(string $cuerpo): ?array
    {
        foreach (preg_split('/\r?\n/', $cuerpo) as $linea) {
            $linea = trim($linea);

            if (str_starts_with($linea, 'data:')) {
                $json = trim(substr($linea, strlen('data:')));
                $decodificado = json_decode($json, true);

                return is_array($decodificado) ? $decodificado : null;
            }
        }

        $decodificado = json_decode($cuerpo, true);

        return is_array($decodificado) ? $decodificado : null;
    }

    private function extraerMensajeErrorTool(array $resultado): string
    {
        $texto = $resultado['content'][0]['text'] ?? null;

        return (is_string($texto) && $texto !== '') ? $texto : 'Tiendanube rechazó la operación.';
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

    private function registrarExito(string $tool, string $sentido, int $codigo, int $duracionMs): void
    {
        $this->registrarLog($tool, $sentido, 'exito', $codigo, $duracionMs);
    }

    private function registrarError(string $tool, string $sentido, ?int $codigo, string $mensaje, ?int $duracionMs = null): RespuestaTiendanube
    {
        $this->registrarLog($tool, $sentido, 'error', $codigo, $duracionMs, $mensaje);

        return RespuestaTiendanube::error($mensaje, $codigo);
    }

    private function registrarBloqueada(string $tool, string $sentido, array $argumentos, string $mensaje): RespuestaTiendanube
    {
        $this->registrarLog($tool, $sentido, 'bloqueada', null, null, null, $argumentos ? json_encode($argumentos) : null);

        return RespuestaTiendanube::bloqueada($mensaje);
    }

    private function registrarLog(
        string $tool,
        string $sentido,
        string $resultado,
        ?int $codigoHttp,
        ?int $duracionMs,
        ?string $mensajeError = null,
        ?string $payloadBloqueado = null,
    ): void {
        TiendanubeOperacionLog::registrar([
            'operacion' => $tool,
            'metodo' => 'POST',
            'endpoint' => '/',
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
