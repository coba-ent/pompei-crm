<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integracion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-030/SC-007 — `integraciones.credenciales` nunca aparece en respuestas ni serialización. */
class SeguridadCredencialesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private Integracion $integracion;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        $this->integracion = Integracion::create([
            'canal' => 'tiendanube',
            'credenciales' => ['access_token' => 'secreto-token', 'refresh_token' => 'secreto-refresh', 'expires_at' => now()->addDays(30), 'cuenta_id' => '1'],
            'config' => ['lista_precio_id' => null, 'deposito_id' => null],
            'estado' => 'conectado',
            'activo' => true,
        ]);
    }

    public function test_model_toarray_no_incluye_credenciales(): void
    {
        $this->assertArrayNotHasKey('credenciales', $this->integracion->toArray());
        $this->assertArrayNotHasKey('credenciales', $this->integracion->fresh()->toArray());
    }

    public function test_config_form_no_expone_credenciales(): void
    {
        $respuesta = $this->getJson(route('integraciones.config.form', 'tiendanube'));
        $respuesta->assertOk();
        $respuesta->assertDontSee('secreto-token');
        $respuesta->assertDontSee('secreto-refresh');
        $this->assertArrayNotHasKey('credenciales', $respuesta->json());
    }

    public function test_panel_index_no_expone_credenciales(): void
    {
        $respuesta = $this->get(route('integraciones.index'));
        $respuesta->assertOk();
        $respuesta->assertDontSee('secreto-token');
        $respuesta->assertDontSee('secreto-refresh');
    }

    public function test_resultados_de_sync_no_exponen_credenciales(): void
    {
        $respuesta = $this->getJson(route('integraciones.sync.resultados'));
        $respuesta->assertOk();
        $respuesta->assertDontSee('secreto-token');
        $respuesta->assertDontSee('secreto-refresh');
    }
}
