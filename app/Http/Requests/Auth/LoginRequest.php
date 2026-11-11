<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\CredencialRecordada;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required_without:credencial_recordada', 'nullable', 'string'],
            'credencial_recordada' => ['nullable', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Sin contraseña escrita: entra con la credencial que "Recordarme" dejó guardada en
        // este navegador (ver CredencialRecordada). Si ya no sirve (cambió la contraseña,
        // usuario inactivo, otro email), el error va en `credencial_recordada` para que el
        // login la borre del navegador y pida la contraseña.
        if (! $this->filled('password')) {
            $user = app(CredencialRecordada::class)
                ->resolver((string) $this->input('credencial_recordada'), (string) $this->input('email'));

            if (! $user) {
                RateLimiter::hit($this->throttleKey());

                throw ValidationException::withMessages([
                    'credencial_recordada' => 'La contraseña recordada en este equipo ya no es válida. Escribila de nuevo.',
                ]);
            }

            Auth::login($user);
            RateLimiter::clear($this->throttleKey());

            return;
        }

        $credenciales = $this->only('email', 'password') + ['activo' => true];

        // "Recordarme" ya no usa la cookie remember de Laravel: recuerda la cuenta en el
        // navegador (CredencialRecordada). Quedarse logueado es "Mantener sesión iniciada".
        if (! Auth::attempt($credenciales)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
