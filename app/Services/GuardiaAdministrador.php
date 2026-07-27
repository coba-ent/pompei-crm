<?php

namespace App\Services;

use App\Models\User;

/**
 * Regla "nunca sin administrador" (research D9, FR-012): no se puede desactivar, quitarle el rol Admin,
 * ni auto-dar de baja al último usuario activo con rol Admin — se evalúa excluyendo al usuario afectado.
 */
class GuardiaAdministrador
{
    public function quedanOtrosAdminsActivos(User $usuarioAfectado): bool
    {
        return User::query()
            ->where('id', '!=', $usuarioAfectado->id)
            ->where('activo', true)
            ->whereHas('roles', fn ($query) => $query->where('nombre', 'Admin'))
            ->exists();
    }

    public function esUltimoAdminActivo(User $usuario): bool
    {
        return $usuario->activo
            && $usuario->esAdmin()
            && ! $this->quedanOtrosAdminsActivos($usuario);
    }
}
