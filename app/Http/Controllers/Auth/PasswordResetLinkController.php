<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetLinkController extends Controller
{
    private const MENSAJE_GENERICO = 'Si el email existe, te enviamos un link para recuperar tu contraseña.';

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $limiterKey = 'password-reset:'.strtolower($request->input('email'));

        if (RateLimiter::tooManyAttempts($limiterKey, 1)) {
            return response()->json(['message' => self::MENSAJE_GENERICO]);
        }

        RateLimiter::hit($limiterKey, 60);

        $usuario = User::where('email', $request->input('email'))->first();

        if ($usuario && $usuario->activo) {
            Password::sendResetLink(['email' => $usuario->email]);
        }

        return response()->json(['message' => self::MENSAJE_GENERICO]);
    }
}
