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
 */
class AplicarDuracionSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $mantenerSesion = $request->session()->get('mantener_sesion_activa', false);
            config(['session.expire_on_close' => ! $mantenerSesion]);
            config(['session.lifetime' => $mantenerSesion ? 60 * 24 * 30 : 120]);
        }

        return $next($request);
    }
}
