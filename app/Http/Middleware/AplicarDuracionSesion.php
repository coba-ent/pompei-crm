<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Duración de la sesión según la preferencia "mantener sesión iniciada" guardada en la
 * sesión al loguearse.
 *
 * - Con "mantener": la cookie dura 30 días y la sesión sobrevive 30 días sin actividad.
 * - Sin "mantener": la cookie muere al cerrar el navegador y la sesión se corta a las
 *   2 h sin actividad.
 *
 * El plazo del lado servidor NO se puede ajustar desde acá: Laravel arma el handler de
 * sesión (que descarta sesiones inactivas) en StartSession, antes que este middleware, con
 * el `session.lifetime` del archivo de config. Antes ese valor era 120 y "mantener sesión"
 * moría a las 2 h aunque la cookie durara 30 días. Por eso `config/session.php` fija 30
 * días para todos y el corte de 2 h de quien no tildó "mantener" se hace acá, a mano,
 * con `ultima_actividad`.
 *
 * Lo de la cookie sí se ajusta en cada request porque StartSession la vuelve a emitir en
 * cada response con la config vigente. En el request del login mismo este middleware corre
 * antes que el controller (lee la preferencia vieja), por eso
 * AuthenticatedSessionController::store() llama a `aplicar()` otra vez.
 */
class AplicarDuracionSesion
{
    public const MINUTOS_MANTENER = 60 * 24 * 30;

    public const MINUTOS_SIN_MANTENER = 120;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $sesion = $request->session();
            $mantener = (bool) $sesion->get('mantener_sesion_activa', false);
            $ultima = $sesion->get('ultima_actividad');

            $vencida = ! $mantener && $ultima && (time() - (int) $ultima) > self::MINUTOS_SIN_MANTENER * 60;
            $estabaLogueado = $vencida && Auth::guard('web')->check();

            if ($vencida) {
                Auth::guard('web')->logout();
                $sesion->invalidate();
                $sesion->regenerateToken();
            }

            $sesion->put('ultima_actividad', time());

            // Hay que cortar acá mismo: por la prioridad de middlewares de Laravel, el `auth`
            // de la ruta ya corrió antes que éste y dejó pasar el request, así que el
            // controller recibiría un usuario null.
            if ($estabaLogueado) {
                static::aplicar(false);

                return $request->expectsJson()
                    ? response()->json(['message' => 'La sesión venció por inactividad. Volvé a ingresar.'], 401)
                    : redirect()->guest(route('login'));
            }

            static::aplicar((bool) $sesion->get('mantener_sesion_activa', false));
        }

        return $next($request);
    }

    public static function aplicar(bool $mantenerSesion): void
    {
        config(['session.expire_on_close' => ! $mantenerSesion]);
        config(['session.lifetime' => $mantenerSesion ? self::MINUTOS_MANTENER : self::MINUTOS_SIN_MANTENER]);
    }
}
