<?php

namespace Tests\Feature\Informes;

use App\Models\Rol;
use App\Models\User;

/**
 * Los tres informes de la spec 067 están detrás de `permiso:informes.ver`, y el usuario que
 * `Tests\TestCase` autentica por defecto no tiene ningún rol. Sin esto, todos los tests de esta
 * carpeta darían 403 antes de llegar a ejercitar un solo cálculo.
 */
trait ConPermisoInformes
{
    protected function autenticarConPermisoInformes(): User
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);

        $usuario = User::factory()->create();
        $usuario->roles()->attach($admin);

        $this->actingAs($usuario);

        return $usuario;
    }
}
