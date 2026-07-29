<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US1 (spec 015): credenciales de la Aplicación personalizada. FR-001..FR-006.
 * US2/FR-004: "Probar conexión" con configuración incompleta.
 */
class TiendanubeConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
    }

    public function test_la_pantalla_de_configuracion_renderiza(): void
    {
        $this->get(route('configuracion.tiendanube.index'))->assertOk();
    }

    public function test_un_usuario_sin_permiso_recibe_403(): void
    {
        // Reemplaza al usuario admin de este setUp() por uno sin roles.
        $this->actingAs(\App\Models\User::factory()->create());

        $this->get(route('configuracion.tiendanube.index'))->assertStatus(403);
    }

    public function test_sin_configurar_el_estado_es_no_configurada(): void
    {
        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('estado', EstadoConexion::NoConfigurada->value)
            ->assertJsonPath('configuracion', null)
            ->assertJsonPath('tienda', null);
    }

    public function test_guarda_credenciales_validas(): void
    {
        $response = $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => 'token-de-acceso-de-prueba',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame('1234567', $configuracion->store_id);
        $this->assertNotNull($configuracion->credenciales_guardadas_en);
    }

    public function test_el_token_se_persiste_cifrado_y_nunca_aparece_en_la_respuesta(): void
    {
        $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => 'token-secreto-de-prueba-123',
        ])->assertOk();

        $crudo = \DB::table('tn_configuracion')->first();
        $this->assertNotSame('token-secreto-de-prueba-123', $crudo->access_token);
        $this->assertStringNotContainsString('token-secreto-de-prueba-123', $crudo->access_token);

        $respuestaEstado = $this->getJson(route('configuracion.tiendanube.estado'));
        $respuestaEstado->assertOk();
        $respuestaEstado->assertJsonMissingPath('configuracion.access_token');
        $this->assertStringNotContainsString('token-secreto-de-prueba-123', $respuestaEstado->getContent());
        $respuestaEstado->assertJsonPath('configuracion.token_cargado', true);
    }

    public function test_el_token_vacio_al_editar_conserva_el_guardado(): void
    {
        $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => 'token-original-de-prueba',
        ])->assertOk();

        $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => '',
        ])->assertOk();

        $this->assertSame('token-original-de-prueba', TiendanubeConfiguracion::actual()->access_token);
    }

    public function test_el_token_se_normaliza_quitando_espacios_alrededor(): void
    {
        $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => '   token-con-espacios   ',
        ])->assertOk();

        $this->assertSame('token-con-espacios', TiendanubeConfiguracion::actual()->access_token);
    }

    public function test_validaciones_por_campo_devuelven_422(): void
    {
        $response = $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => 'no-numerico',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['store_id']);
    }

    public function test_al_menos_un_campo_es_obligatorio(): void
    {
        $response = $this->putJson(route('configuracion.tiendanube.credenciales'), []);

        $response->assertStatus(422);
    }

    public function test_reemplazar_el_token_con_conexion_activa_la_invalida_hasta_volver_a_probar(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'store_id' => '1234567',
            'access_token' => 'token-original',
            'estado' => EstadoConexion::Conectada,
            'nombre_tienda' => 'Mi Tienda',
        ]);

        $response = $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'access_token' => 'token-nuevo',
        ]);

        $response->assertOk()->assertJsonPath('advertencia', 'La conexión anterior queda invalidada hasta que la vuelvas a probar.');
        $this->assertSame(EstadoConexion::Desconectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_probar_conexion_con_configuracion_incompleta_devuelve_409_sin_llamar_a_la_api(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertStatus(409)->assertJsonPath('ok', false);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_probar_conexion_sin_store_id_nombra_el_dato_faltante(): void
    {
        TiendanubeConfiguracion::actual()->update(['access_token' => 'token-de-prueba']);

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertStatus(409);
        $this->assertStringContainsString('identificador de tienda', $response->json('mensaje'));
    }

    public function test_ningun_endpoint_de_la_superficie_expone_secretos(): void
    {
        $this->putJson(route('configuracion.tiendanube.credenciales'), [
            'store_id' => '1234567',
            'access_token' => 'token-secreto-de-superficie',
        ])->assertOk();

        $endpoints = [
            fn () => $this->getJson(route('configuracion.tiendanube.estado')),
            fn () => $this->getJson(route('configuracion.tiendanube.historial')),
        ];

        foreach ($endpoints as $llamar) {
            $contenido = $llamar()->getContent();
            $this->assertStringNotContainsString('token-secreto-de-superficie', $contenido);
            $this->assertStringNotContainsString('access_token', $contenido);
        }
    }
}
