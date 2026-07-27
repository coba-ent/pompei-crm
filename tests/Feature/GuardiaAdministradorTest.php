<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regla "nunca sin administrador" (research D9, FR-012). */
class GuardiaAdministradorTest extends TestCase
{
    use RefreshDatabase;

    private function crearAdmin(): Rol
    {
        return Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
    }

    public function test_no_se_puede_dar_de_baja_al_unico_administrador_activo(): void
    {
        $admin = $this->crearAdmin();
        $usuario = User::factory()->create();
        $usuario->roles()->attach($admin->id);

        $this->actingAs($usuario)
            ->patchJson(route('configuracion.usuarios.estado', $usuario))
            ->assertStatus(422);

        $this->assertTrue($usuario->fresh()->activo);
    }

    public function test_permite_dar_de_baja_a_un_administrador_si_hay_otro_activo(): void
    {
        $admin = $this->crearAdmin();
        $usuario1 = User::factory()->create();
        $usuario2 = User::factory()->create();
        $usuario1->roles()->attach($admin->id);
        $usuario2->roles()->attach($admin->id);

        $this->actingAs($usuario1)
            ->patchJson(route('configuracion.usuarios.estado', $usuario1))
            ->assertOk();

        $this->assertFalse($usuario1->fresh()->activo);
    }

    public function test_no_se_puede_quitar_el_rol_admin_al_ultimo_administrador(): void
    {
        $admin = $this->crearAdmin();
        $vendedor = Rol::create(['nombre' => 'Vendedor', 'es_sistema' => false]);
        $usuario = User::factory()->create();
        $usuario->roles()->attach($admin->id);

        $this->actingAs($usuario)
            ->putJson(route('configuracion.usuarios.update', $usuario), [
                'name' => $usuario->name,
                'email' => $usuario->email,
                'roles' => [$vendedor->id],
            ])
            ->assertStatus(422);

        $this->assertTrue($usuario->fresh()->esAdmin());
    }

    public function test_permite_quitar_el_rol_admin_si_hay_otro_administrador_activo(): void
    {
        $admin = $this->crearAdmin();
        $usuario1 = User::factory()->create();
        $usuario2 = User::factory()->create();
        $usuario1->roles()->attach($admin->id);
        $usuario2->roles()->attach($admin->id);

        $this->actingAs($usuario1)
            ->putJson(route('configuracion.usuarios.update', $usuario1), [
                'name' => $usuario1->name,
                'email' => $usuario1->email,
                'roles' => [],
            ])
            ->assertOk();

        $this->assertFalse($usuario1->fresh()->esAdmin());
    }

    public function test_auto_baja_del_unico_administrador_es_rechazada(): void
    {
        $admin = $this->crearAdmin();
        $usuario = User::factory()->create();
        $usuario->roles()->attach($admin->id);

        $this->actingAs($usuario)
            ->patchJson(route('configuracion.usuarios.estado', $usuario))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
