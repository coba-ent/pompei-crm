<?php

namespace Tests\Feature\Configuracion;

use App\Models\FuncionAvanzada;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 043 (FR-007a): tab por defecto "Funciones Avanzadas"; tabs de funciones con pantalla propia sólo visibles si están activas. */
class TabsConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        return $user;
    }

    public function test_el_tab_funciones_avanzadas_esta_activo_por_defecto(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('configuracion.index'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'nav-link active',
            'id="tab-funciones-btn"',
        ], false);
    }

    public function test_tab_de_funcion_inactiva_no_aparece(): void
    {
        $this->actingAsAdmin();
        FuncionAvanzada::create([
            'clave' => 'depositos', 'nombre' => 'Depósitos', 'descripcion' => 'x',
            'orden' => 1, 'disponible' => true, 'activa' => false,
        ]);

        $response = $this->get(route('configuracion.index'));

        $response->assertOk();
        preg_match('/<li class="([^"]*)" role="presentation" data-tab-clave="depositos">/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('d-none', $matches[1]);
    }

    public function test_tab_de_funcion_activa_aparece_sin_d_none(): void
    {
        $this->actingAsAdmin();
        FuncionAvanzada::create([
            'clave' => 'depositos', 'nombre' => 'Depósitos', 'descripcion' => 'x',
            'orden' => 1, 'disponible' => true, 'activa' => true,
        ]);

        $response = $this->get(route('configuracion.index'));

        $response->assertOk();
        preg_match('/<li class="([^"]*)" role="presentation" data-tab-clave="depositos">/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('d-none', $matches[1]);
    }
}
