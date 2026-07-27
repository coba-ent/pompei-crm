<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AutorizacionPermisoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->get('/__test/permiso-protegido', fn () => 'ok')
            ->middleware('permiso:modulo.accion');
    }

    public function test_usuario_sin_el_permiso_recibe_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/__test/permiso-protegido')
            ->assertForbidden();
    }

    public function test_usuario_con_rol_admin_pasa_cualquier_permiso(): void
    {
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);

        $this->actingAs($user)
            ->get('/__test/permiso-protegido')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_usuario_con_rol_que_tiene_el_permiso_pasa(): void
    {
        $rol = Rol::create(['nombre' => 'Vendedor', 'es_sistema' => false]);
        $permiso = \App\Models\Permiso::create(['codigo' => 'modulo.accion', 'descripcion' => 'Test', 'modulo' => 'modulo']);
        $rol->permisos()->attach($permiso->id);

        $user = User::factory()->create();
        $user->roles()->attach($rol->id);

        $this->actingAs($user)
            ->get('/__test/permiso-protegido')
            ->assertOk();
    }

    public function test_usuario_no_autenticado_es_redirigido_a_login(): void
    {
        $this->get('/__test/permiso-protegido')
            ->assertRedirect(route('login'));
    }
}
