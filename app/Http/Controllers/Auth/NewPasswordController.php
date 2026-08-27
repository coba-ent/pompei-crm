<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        $token = $request->route('token');
        $email = $request->query('email');

        $tokenValido = $email && Password::tokenExists(
            User::where('email', $email)->first() ?? new User,
            $token
        );

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $email,
            'tokenValido' => (bool) $tokenValido,
        ]);
    }

    public function store(NewPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $usuario) use ($request) {
                $usuario->forceFill([
                    'password' => Hash::make($request->string('password')),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'errors' => ['email' => ['Este link ya no es válido. Pedí uno nuevo desde el login.']],
            ], 422);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya podés iniciar sesión.']);
    }
}
