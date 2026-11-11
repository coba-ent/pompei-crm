<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * "Recordarme" del login: recuerda en el navegador (localStorage) la última cuenta con la
 * que se entró con el check tildado, para no tener que volver a escribir mail y contraseña.
 *
 * La contraseña NO se guarda en el navegador, ni en claro ni cifrada. Se guarda una
 * credencial cifrada con la APP_KEY que contiene el id del usuario y una firma de su hash
 * de contraseña actual. El login acepta esa credencial en lugar de la contraseña. Si se
 * cambia la contraseña (Mi Perfil, reset por mail), la firma deja de coincidir y la
 * credencial queda inválida sola: hay que escribir la contraseña nueva una vez.
 */
class CredencialRecordada
{
    public function emitir(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $user->getKey(),
            'firma' => $this->firma($user),
        ]));
    }

    /**
     * Devuelve el usuario si la credencial es válida para ese email y el usuario sigue
     * activo con la misma contraseña; null en cualquier otro caso.
     */
    public function resolver(string $credencial, string $email): ?User
    {
        try {
            $datos = json_decode(Crypt::decryptString($credencial), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($datos) || empty($datos['uid']) || empty($datos['firma'])) {
            return null;
        }

        $user = User::find($datos['uid']);

        if (! $user
            || ! $user->activo
            || mb_strtolower($user->email) !== mb_strtolower(trim($email))
            || ! hash_equals($this->firma($user), (string) $datos['firma'])) {
            return null;
        }

        return $user;
    }

    private function firma(User $user): string
    {
        return hash_hmac('sha256', 'login-recordado|'.$user->getAuthPassword(), (string) config('app.key'));
    }
}
