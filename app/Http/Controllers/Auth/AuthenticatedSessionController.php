<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AplicarDuracionSesion;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $mantenerSesion = $request->boolean('mantener_sesion');
        $request->session()->put('mantener_sesion_activa', $mantenerSesion);

        // AplicarDuracionSesion (middleware) ya corrió antes que este controller en el
        // pipeline de `web`, así que leyó la preferencia vieja (la de antes de este login).
        // Sin este segundo llamado, la cookie de ESTA respuesta queda con la duración
        // corta aunque el usuario haya tildado "Mantener sesión iniciada" — recién se
        // corregía en el próximo request.
        AplicarDuracionSesion::aplicar($mantenerSesion);

        return redirect()->intended(route('dashboard.index', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
