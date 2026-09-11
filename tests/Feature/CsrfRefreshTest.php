<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * "CSRF token mismatch" en producción (ver resources/js/csrf-refresh.js): Laravel rota el token de
 * sesión sin cerrar la sesión de auth, y cada bundle de pantalla lo lee una sola vez al cargar. La
 * ruta `csrf-token` es la pieza de backend de ese arreglo — sirve para que el JS pida un token
 * fresco sin necesitar volver a loguearse.
 */
class CsrfRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_un_token_csrf_valido_para_un_usuario_autenticado(): void
    {
        $response = $this->getJson(route('csrf.token'));

        $response->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(csrf_token(), $response->json('token'));
    }

    /**
     * `VerifyCsrfToken::handle()` se salta la verificación por completo cuando
     * `app()->runningUnitTests()` es true (comportamiento nativo de Laravel, no de este proyecto,
     * y no hay forma de desactivarlo desde el test) — así que no se puede reproducir el 419 real
     * vía HTTP ni llamando a `handle()` directo. Se prueba en cambio `tokensMatch()` (protected,
     * vía Reflection), que es el método donde realmente vive la comparación: el mismo criterio
     * exacto que usa `handle()` en producción, sin el atajo de testing en el medio.
     */
    public function test_el_token_devuelto_por_la_ruta_coincide_con_el_que_espera_el_middleware_csrf(): void
    {
        $middleware = app(ValidateCsrfToken::class);
        $tokensMatch = new \ReflectionMethod($middleware, 'tokensMatch');

        $request = Request::create('/cualquier-ruta', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $request->headers->set('X-CSRF-TOKEN', 'token-viejo-que-no-coincide');
        $this->assertFalse($tokensMatch->invoke($middleware, $request), 'Un token que no coincide con el de la sesión no tiene que pasar.');

        // El token que devuelve la ruta csrf-token es el mismo que la sesión espera.
        $tokenFresco = $this->getJson(route('csrf.token'))->json('token');
        $request->headers->set('X-CSRF-TOKEN', $tokenFresco);
        $this->assertTrue($tokensMatch->invoke($middleware, $request), 'El token que devuelve csrf-token tiene que pasar la verificación.');
    }
}
