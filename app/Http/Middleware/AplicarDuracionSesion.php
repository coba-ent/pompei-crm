<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajusta la duración de la cookie de sesión según la preferencia "mantener sesión
 * iniciada" guardada en la sesión al loguearse. Corre en cada request (no sólo en el
 * login) porque StartSession vuelve a emitir la cookie de sesión en cada response,
 * usando siempre la config vigente en ese momento.
 *
 * En el request del login mismo esto NO alcanza: este middleware corre ANTES que el
 * controller (es el orden normal del pipeline de `web`), así que lee
 * `mantener_sesion_activa` de la sesión un instante antes de que
 * `AuthenticatedSessionController::store()` la guarde — la cookie de esa respuesta
 * salía siempre con la duración vieja (nunca "mantener sesión", aunque el checkbox
 * estuviera tildado), y recién se corregía en el segundo request. Por eso el
 * controller llama a `aplicar()` de nuevo, a mano, justo después de guardar la
 * preferencia — ver AuthenticatedSessionController::store().
 */
class AplicarDuracionSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            static::aplicar((bool) $request->session()->get('mantener_sesion_activa', false));
        }

        return $next($request);
    }

    public static function aplicar(bool $mantenerSesion): void
    {
        config(['session.expire_on_close' => ! $mantenerSesion]);
        config(['session.lifetime' => $mantenerSesion ? 60 * 24 * 30 : 120]);
    }
}
