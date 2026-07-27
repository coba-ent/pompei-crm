<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware 'permiso:<codigo>' — el rol Admin pasa siempre (Gate::before). */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $codigo): Response
    {
        if (! $request->user() || ! $request->user()->tienePermiso($codigo)) {
            abort(403, 'No tenés permiso para acceder a esta sección.');
        }

        return $next($request);
    }
}
