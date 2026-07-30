<?php

namespace App\Http\Controllers\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Http\Controllers\Controller;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Services\Tiendanube\ClienteTiendanube;
use App\Services\Tiendanube\Excepciones\RegistroClienteFallidoException;
use App\Services\Tiendanube\RegistradorClienteOAuth;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Flujo OAuth 2.1 + PKCE contra admin-mcp.tiendanube.com (spec 019, corrige el
 * modelo de Aplicación personalizada de spec 015). Calcado de
 * MercadoLibreOAuthController (spec 011) en su forma general, sin renovación
 * (no hay refresh_token en la práctica, research.md §R3) y con auto-registro
 * de cliente OAuth (RegistradorClienteOAuth) antes de armar la URL de
 * autorización. `code_verifier`/`state` viajan en sesión (FR-002), no en una
 * tabla propia: no hace falta resistir un cambio de dispositivo entre
 * "Conectar" y la vuelta del navegador.
 */
class TiendanubeOAuthController extends Controller
{
    private const AUTHORIZE_URL = 'https://admin-mcp.tiendanube.com/authorize';

    private const TOKEN_URL = 'https://admin-mcp.tiendanube.com/token';

    private const SCOPES = 'read_products write_products read_orders write_orders read_customers write_customers read_content write_content read_coupons write_coupons write_scripts write_shipping';

    private const SESSION_STATE = 'tn_oauth_state';

    private const SESSION_CODE_VERIFIER = 'tn_oauth_code_verifier';

    private const SESSION_EXPIRA_EN = 'tn_oauth_expira_en';

    public function conectar(Request $request, RegistradorClienteOAuth $registrador): RedirectResponse
    {
        try {
            $configuracion = $registrador->registrarSiHaceFalta();
        } catch (RegistroClienteFallidoException $e) {
            return redirect()->route('configuracion.tiendanube.index')->with('tn_error', $e->getMessage());
        }

        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        $request->session()->put([
            self::SESSION_STATE => $state,
            self::SESSION_CODE_VERIFIER => $codeVerifier,
            self::SESSION_EXPIRA_EN => now()->addMinutes(10)->timestamp,
        ]);

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $configuracion->client_id,
            'redirect_uri' => route('configuracion.tiendanube.callback'),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, ClienteTiendanube $cliente): RedirectResponse
    {
        if ($request->filled('error')) {
            $mensaje = $request->get('error') === 'access_denied'
                ? 'Cancelaste la autorización con Tiendanube. No se realizó ningún cambio.'
                : 'Tiendanube rechazó la autorización. Iniciá la conexión de nuevo.';

            return redirect()->route('configuracion.tiendanube.index')->with('tn_info', $mensaje);
        }

        $code = $request->get('code');
        $state = $request->get('state');

        if (! $this->stateValido($request, $state)) {
            $this->olvidarSesionOAuth($request);

            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_error', 'El enlace de autorización no es válido o ya venció. Iniciá la conexión de nuevo.');
        }

        $codeVerifier = $request->session()->get(self::SESSION_CODE_VERIFIER);
        $this->olvidarSesionOAuth($request); // de un solo uso (edge case: doble intercambio del mismo código).

        if (! $code || ! $codeVerifier) {
            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_error', 'La respuesta de Tiendanube no incluyó los datos esperados. Iniciá la conexión de nuevo.');
        }

        $configuracion = TiendanubeConfiguracion::actual();

        try {
            $tokenAnterior = $configuracion->access_token;
        } catch (DecryptException $e) {
            $tokenAnterior = null;
        }
        $scopesAnteriores = $configuracion->scopes_otorgados;
        $estadoAnterior = $configuracion->estado;
        $tokenExpiraEnAnterior = $configuracion->token_expira_en;

        $tokenData = $this->intercambiarCodigo($configuracion, $code, $codeVerifier);

        if ($tokenData === null) {
            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_error', 'No se pudo completar la conexión con Tiendanube. Intentá de nuevo.');
        }

        // FR-003: se guarda cifrado antes de verificar — la verificación FR-003a
        // necesita pasar por ClienteTiendanube (kill-switch/log únicos), que lee
        // el token desde tn_configuracion.
        $configuracion->access_token = $tokenData['access_token'];
        $configuracion->scopes_otorgados = $tokenData['scope'] ?? null;
        $configuracion->token_expira_en = now()->addSeconds((int) ($tokenData['expires_in'] ?? 31536000));
        $configuracion->save();

        $verificacion = $cliente->leer('list_products', ['page' => 1, 'page_size' => 1], ['omitir_guard_funcion' => true]);

        if ($verificacion->fallo()) {
            // FR-003a/edge case: la verificación falla → la conexión NO queda
            // conectada. Se restaura lo que había antes (reconexión fallida no
            // pisa una conexión previa que sí funcionaba).
            $configuracion->access_token = $tokenAnterior;
            $configuracion->scopes_otorgados = $scopesAnteriores;
            $configuracion->estado = $estadoAnterior;
            $configuracion->token_expira_en = $tokenExpiraEnAnterior;
            $configuracion->save();

            return redirect()->route('configuracion.tiendanube.index')
                ->with('tn_error', 'Se obtuvo el token pero no se pudo verificar la conexión: '.($verificacion->mensajeError ?? 'error desconocido').'.');
        }

        $configuracion->productos_total = (int) ($verificacion->datos['pagination']['total_elements'] ?? 0);
        $configuracion->conectada_en = now();
        $configuracion->estado = EstadoConexion::Conectada;
        $configuracion->ultimo_error = null;
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

        return redirect()->route('configuracion.tiendanube.index')->with('tn_exito', 'Tiendanube conectado correctamente.');
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
        $request->session()->forget([self::SESSION_STATE, self::SESSION_CODE_VERIFIER, self::SESSION_EXPIRA_EN]);
    }

    private function intercambiarCodigo(TiendanubeConfiguracion $configuracion, string $code, string $codeVerifier): ?array
    {
        $inicio = microtime(true);

        try {
            $clientSecret = $configuracion->client_secret;
        } catch (DecryptException $e) {
            $this->log('error', null, $this->duracionMs($inicio), 'Las credenciales del cliente OAuth no pueden leerse.');

            return null;
        }

        if (blank($configuracion->client_id) || blank($clientSecret)) {
            $this->log('error', null, $this->duracionMs($inicio), 'No hay un cliente OAuth registrado contra Tiendanube.');

            return null;
        }

        try {
            $respuesta = Http::asForm()->timeout(30)->connectTimeout(10)->acceptJson()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => route('configuracion.tiendanube.callback'),
                    'client_id' => $configuracion->client_id,
                    'client_secret' => $clientSecret,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (ConnectionException $e) {
            $this->log('error', null, $this->duracionMs($inicio), 'No se pudo conectar con Tiendanube.');

            return null;
        }

        $duracionMs = $this->duracionMs($inicio);

        if (! $respuesta->successful() || blank($respuesta->json('access_token'))) {
            $this->log('error', $respuesta->status(), $duracionMs, 'Tiendanube rechazó el intercambio de código por token.');

            return null;
        }

        $this->log('exito', $respuesta->status(), $duracionMs);

        return $respuesta->json();
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function log(string $resultado, ?int $codigoHttp, ?int $duracionMs, ?string $mensajeError = null): void
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'intercambiar_token',
            'metodo' => 'POST',
            'endpoint' => '/token',
            'sentido' => 'escritura',
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'usuario_id' => auth()->id(),
        ]);
    }
}
