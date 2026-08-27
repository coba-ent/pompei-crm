<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** US3 (spec 081) — cambio de contraseña propio desde una sesión activa (pantalla Empresa/Mi Perfil). */
class PerfilPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private function loguearAdminCon(string $password): User
    {
        $usuario = User::factory()->create(['password' => Hash::make($password)]);
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        $usuario->roles()->attach($admin->id);
        $this->actingAs($usuario);

        return $usuario;
    }

    public function test_contrasena_actual_correcta_actualiza_la_contrasena(): void
    {
        $usuario = $this->loguearAdminCon('claveActual123');

        $response = $this->putJson(route('configuracion.mi-perfil.contrasena.actualizar'), [
            'password_actual' => 'claveActual123',
            'password' => 'claveNueva456',
            'password_confirmation' => 'claveNueva456',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Contraseña actualizada correctamente.');
        $this->assertTrue(Hash::check('claveNueva456', $usuario->fresh()->password));
    }

    public function test_contrasena_actual_incorrecta_devuelve_422_sin_actualizar(): void
    {
        $usuario = $this->loguearAdminCon('claveActual123');

        $response = $this->putJson(route('configuracion.mi-perfil.contrasena.actualizar'), [
            'password_actual' => 'clave-incorrecta',
            'password' => 'claveNueva456',
            'password_confirmation' => 'claveNueva456',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.password_actual.0', 'La contraseña actual no es correcta.');
        $this->assertTrue(Hash::check('claveActual123', $usuario->fresh()->password));
    }
}
