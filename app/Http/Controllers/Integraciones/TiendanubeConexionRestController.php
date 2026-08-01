<?php

namespace App\Http\Controllers\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Http\Controllers\Controller;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Services\Tiendanube\VerificadorConexionRest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Conexión OAuth clásica contra la Application del Partner Portal de
 * Tiendanube (spec 022, aditiva a spec 019) — emite tokens para la REST API
 * estándar (api.tiendanube.com), no para el servidor MCP. Completamente
 * aislada de TiendanubeOAuthController/TiendanubeConfiguracionController:
 * tabla, historial, claves de sesión y mensajes flash propios (FR-008/FR-013).
 * Sin PKCE ni redirect_uri por query (research.md R2): el redirect_uri es fijo,
 * configurado a mano en el Partner Portal.
 */
class TiendanubeConexionRestController extends Controller
{
    private const AUTHORIZE_URL_BASE = 'https://www.tiendanube.com/apps/';

    private const TOKEN_URL = 'https://www.tiendanube.com/apps/authorize/token';

    private const SESSION_STATE = 'tn_rest_oauth_state';

    private const SESSION_EXPIRA_EN = 'tn_rest_oauth_expira_en';

    public function conectarRest(Request $request): RedirectResponse
    {
        $clientId = config('integraciones.tiendanube.client_id');
        $state = Str::random(40);

        $request->session()->put([
            self::SESSION_STATE => $state,
            self::SESSION_EXPIRA_EN => now()->addMinutes(10)->timestamp,
        ]);

        $url = self::AUTHORIZE_URL_BASE.$clientId.'/authorize?'.http_build_query(['state' => $state]);

        return redirect()->away($url);
    }

    public function callbackRest(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $mensaje = $request->get('error') === 'access_denied'
                ? 'Cancelaste la autorización con Tiendanube. No se realizó ningún cambio.'
                : 'Tiendanube rechazó la autorización. Iniciá la conexión de nuevo.';

            return redirect()->route('configuracion.tiendanube.index')->with('tn_rest_info', $mensaje);
        }

        $code = $request->get('code');
        $state = $request->get('state');

        if (! $this->stateValido($request, $state)) {
            $this->olvidarSesionOAuth($request);

            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_rest_error', 'El enlace de autorización no es válido o ya venció. Iniciá la conexión de nuevo.');
        }

        $this->olvidarSesionOAuth($request); // de un solo uso (edge case: doble intercambio del mismo código).

        if (! $code) {
            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_rest_error', 'La respuesta de Tiendanube no incluyó los datos esperados. Iniciá la conexión de nuevo.');
        }

        $conexion = TiendanubeConexionRest::actual();

        try {
            $tokenAnterior = $conexion->access_token;
        } catch (DecryptException $e) {
            $tokenAnterior = null;
        }
        $storeIdAnterior = $conexion->store_id;
        $scopesAnteriores = $conexion->scopes_otorgados;
        $nombreAnterior = $conexion->tienda_nombre;
        $dominioAnterior = $conexion->tienda_dominio;
        $conectadaEnAnterior = $conexion->conectada_en;
        $estadoAnterior = $conexion->estado;

        $tokenData = $this->intercambiarCodigo($code);

        if ($tokenData === null) {
            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_rest_error', 'No se pudo completar la conexión con Tiendanube. Intentá de nuevo.');
        }

        $accessToken = (string) $tokenData['access_token'];
        $storeId = (string) ($tokenData['user_id'] ?? '');

        $inicioVerificacion = microtime(true);
        $verificacion = app(VerificadorConexionRest::class)->verificar($accessToken, $storeId);
        $duracionVerificacionMs = $this->duracionMs($inicioVerificacion);

        $this->logOperacion(
            'verificar', 'GET', '/store', 'lectura',
            $verificacion['ok'] ? 'exito' : 'error',
            $verificacion['codigo_http'], $duracionVerificacionMs,
            $verificacion['ok'] ? null : $verificacion['mensaje_error']
        );

        if (! $verificacion['ok']) {
            // FR-005/edge case reconexión: la verificación falla → NO queda conectada.
            // Se restaura lo que había antes (una reconexión fallida no pisa una
            // conexión previa que sí funcionaba).
            $conexion->access_token = $tokenAnterior;
            $conexion->store_id = $storeIdAnterior;
            $conexion->scopes_otorgados = $scopesAnteriores;
            $conexion->tienda_nombre = $nombreAnterior;
            $conexion->tienda_dominio = $dominioAnterior;
            $conexion->conectada_en = $conectadaEnAnterior;
            $conexion->estado = $estadoAnterior;
            $conexion->save();

            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_rest_error', 'Se obtuvo el token pero no se pudo verificar la conexión: '.($verificacion['mensaje_error'] ?? 'error desconocido').'.');
        }

        $conexion->access_token = $accessToken;
        $conexion->store_id = $storeId;
        $conexion->scopes_otorgados = $tokenData['scope'] ?? null;
        $conexion->tienda_nombre = $verificacion['nombre'];
        $conexion->tienda_dominio = $verificacion['dominio'];
        $conexion->conectada_en = now();
        $conexion->estado = EstadoConexion::Conectada;
        $conexion->ultimo_error = null;
        $conexion->actualizada_por = $request->user()->id;
        $conexion->save();

        return redirect()->route('configuracion.tiendanube.index')->with('tn_rest_exito', 'Conexión REST de Tiendanube establecida correctamente.');
    }

    public function estadoRest(): JsonResponse
    {
        $conexion = TiendanubeConexionRest::actual();
        $noConfigurada = blank($conexion->getRawOriginal('access_token'));

        if ($noConfigurada) {
            return response()->json([
                'ok' => true,
                'estado' => EstadoConexion::NoConfigurada->value,
                'conexion' => null,
            ]);
        }

        try {
            $accessToken = $conexion->access_token;
        } catch (DecryptException $e) {
            $mensaje = 'Las credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?).';
            $conexion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $mensaje]);

            return response()->json([
                'ok' => true,
                'estado' => EstadoConexion::Caida->value,
                'conexion' => $this->datosConexion($conexion),
                'ultimo_error' => $mensaje,
            ]);
        }

        $inicio = microtime(true);
        $verificacion = app(VerificadorConexionRest::class)->verificar($accessToken, (string) $conexion->store_id);
        $duracionMs = $this->duracionMs($inicio);

        $this->logOperacion(
            'verificar', 'GET', '/store', 'lectura',
            $verificacion['ok'] ? 'exito' : 'error',
            $verificacion['codigo_http'], $duracionMs,
            $verificacion['ok'] ? null : $verificacion['mensaje_error']
        );

        if (! $verificacion['ok']) {
            // FR-009: sólo 401/404 (credencial rechazada) marca la conexión como
            // caída. 429/5xx ya agotaron su reintento acotado dentro de
            // VerificadorConexionRest — no son un rechazo de credencial.
            if (in_array($verificacion['codigo_http'], [401, 404], true)) {
                $conexion->update(['estado' => EstadoConexion::Caida, 'ultimo_error' => $verificacion['mensaje_error']]);
            }

            $conexion = $conexion->fresh();

            return response()->json([
                'ok' => true,
                'estado' => $conexion->estado->value,
                'conexion' => $this->datosConexion($conexion),
                'ultimo_error' => $conexion->ultimo_error,
            ]);
        }

        if ($conexion->estado !== EstadoConexion::Conectada) {
            $conexion->update(['estado' => EstadoConexion::Conectada, 'ultimo_error' => null]);
        }

        return response()->json([
            'ok' => true,
            'estado' => EstadoConexion::Conectada->value,
            'conexion' => $this->datosConexion($conexion),
        ]);
    }

    public function desconectarRest(Request $request): JsonResponse
    {
        $conexion = TiendanubeConexionRest::actual();

        $conexion->access_token = null;
        $conexion->store_id = null;
        $conexion->scopes_otorgados = null;
        $conexion->tienda_nombre = null;
        $conexion->tienda_dominio = null;
        $conexion->conectada_en = null;
        $conexion->estado = EstadoConexion::NoConfigurada;
        $conexion->ultimo_error = null;
        $conexion->actualizada_por = $request->user()->id;
        $conexion->save();

        $this->logOperacion('desconectar', 'LOCAL', '-', 'escritura', 'exito', null, null);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Conexión REST de Tiendanube desconectada.',
        ]);
    }

    private function datosConexion(TiendanubeConexionRest $conexion): array
    {
        return [
            'conectada_en' => optional($conexion->conectada_en)->toIso8601String(),
            'scopes_otorgados' => $conexion->scopes_otorgados,
            'tienda_nombre' => $conexion->tienda_nombre,
            'tienda_dominio' => $conexion->tienda_dominio,
        ];
    }

    private function stateValido(Request $request, ?string $state): bool
    {
        $stateSesion = $request->session()->get(self::SESSION_STATE);
        $expiraEn = $request->session()->get(self::SESSION_EXPIRA_EN);

        if (! $state || ! $stateSesion || ! $expiraEn) {
            return false;
        }

        if (! hash_equals((string) $stateSesion, $state)) {
            return false;
        }

        return now()->timestamp <= $expiraEn;
    }

    private function olvidarSesionOAuth(Request $request): void
    {
        $request->session()->forget([self::SESSION_STATE, self::SESSION_EXPIRA_EN]);
    }

    private function intercambiarCodigo(string $code): ?array
    {
        $inicio = microtime(true);

        $clientId = config('integraciones.tiendanube.client_id');
        $clientSecret = config('integraciones.tiendanube.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            $this->logOperacion('conectar', 'POST', '/apps/authorize/token', 'escritura', 'error', null, $this->duracionMs($inicio), 'No hay credenciales configuradas para esta Application.');

            return null;
        }

        try {
            $respuesta = Http::timeout(30)->connectTimeout(10)->acceptJson()
                ->post(self::TOKEN_URL, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ]);
        } catch (ConnectionException $e) {
            $this->logOperacion('conectar', 'POST', '/apps/authorize/token', 'escritura', 'error', null, $this->duracionMs($inicio), 'No se pudo conectar con Tiendanube.');

            return null;
        }

        $duracionMs = $this->duracionMs($inicio);

        if (! $respuesta->successful() || blank($respuesta->json('access_token'))) {
            $this->logOperacion('conectar', 'POST', '/apps/authorize/token', 'escritura', 'error', $respuesta->status(), $duracionMs, 'Tiendanube rechazó el intercambio de código por token.');

            return null;
        }

        $this->logOperacion('conectar', 'POST', '/apps/authorize/token', 'escritura', 'exito', $respuesta->status(), $duracionMs);

        return $respuesta->json();
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function logOperacion(
        string $operacion,
        string $metodo,
        string $endpoint,
        string $sentido,
        string $resultado,
        ?int $codigoHttp,
        ?int $duracionMs,
        ?string $mensajeError = null,
    ): void {
        TiendanubeRestOperacionLog::registrar([
            'operacion' => $operacion,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'sentido' => $sentido,
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'usuario_id' => auth()->id(),
        ]);
    }
}
