<?php

namespace Tests\Feature\Configuracion;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 043 (FR-004/FR-005): "Empresa" y toda "Configuración & Ajustes" gatean por rol Admin, no por permisos granulares. */
class AccesoAdminConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private function rutasProtegidas(): array
    {
        return [
            'configuracion.index',
            'configuracion.mi-perfil.index',
            'configuracion.depositos.index',
            'configuracion.mercadolibre.index',
            'configuracion.tiendanube.index',
            'configuracion.arca.index',
        ];
    }

    public function test_usuario_sin_rol_admin_recibe_403_en_todas_las_pantallas(): void
    {
        $user = User::factory()->create();

        foreach ($this->rutasProtegidas() as $ruta) {
            $this->actingAs($user)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_usuario_con_rol_admin_recibe_200_en_todas_las_pantallas(): void
    {
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);

        foreach ($this->rutasProtegidas() as $ruta) {
            $this->actingAs($user)->get(route($ruta))->assertOk();
        }
    }

    public function test_usuarios_index_ya_no_existe_como_pantalla_propia(): void
    {
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);

        // El GET '/' de listado se eliminó (FR-003); sólo persiste el POST AJAX en la misma URI,
        // por lo que Laravel responde 405 (URI reconocida, método no permitido) y no 404.
        $this->actingAs($user)
            ->get('/configuracion/usuarios')
            ->assertStatus(405);
    }
}
