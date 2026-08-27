<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\ListaPrecio;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US2 (spec 011): configuración de la aplicación de Mercado Libre. FR-009..FR-014.
 * SC-007: el secreto nunca se devuelve a la interfaz.
 */
class MercadoLibreConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_la_pantalla_de_configuracion_renderiza(): void
    {
        $this->get(route('configuracion.mercadolibre.index'))->assertOk();
    }

    public function test_guarda_credenciales_validas(): void
    {
        $response = $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => str_repeat('a', 32),
            'site_id' => 'MLA',
        ]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('requiere_revinculacion', false);

        $configuracion = MercadoLibreConfiguracion::actual();
        $this->assertSame('123456789012', $configuracion->client_id);
        $this->assertSame('MLA', $configuracion->site_id);
    }

    public function test_el_secreto_se_persiste_cifrado_y_nunca_aparece_en_la_respuesta(): void
    {
        $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ])->assertOk();

        $crudo = \DB::table('ml_configuracion')->first();
        $this->assertNotSame('clave-secreta-de-prueba-32chars', $crudo->client_secret);
        $this->assertStringNotContainsString('clave-secreta-de-prueba-32chars', $crudo->client_secret);

        $respuestaEstado = $this->getJson(route('configuracion.mercadolibre.estado'));
        $respuestaEstado->assertOk();
        $respuestaEstado->assertJsonMissingPath('configuracion.client_secret');
        $this->assertStringNotContainsString('clave-secreta-de-prueba-32chars', $respuestaEstado->getContent());
        $respuestaEstado->assertJsonPath('configuracion.secret_cargado', true);
    }

    public function test_el_secreto_vacio_al_editar_conserva_el_guardado(): void
    {
        $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-original-32-chars',
            'site_id' => 'MLA',
        ])->assertOk();

        $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => '',
            'site_id' => 'MLA',
        ])->assertOk();

        $this->assertSame('clave-secreta-original-32-chars', MercadoLibreConfiguracion::actual()->client_secret);
    }

    public function test_validaciones_por_campo_devuelven_422(): void
    {
        $response = $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => 'no-numerico',
            'client_secret' => 'corta',
            'site_id' => 'XX',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id', 'client_secret', 'site_id']);
    }

    /** T060/SC-007: revisión de superficie completa — ningún endpoint expone secretos. */
    public function test_ningun_endpoint_de_la_superficie_expone_secretos(): void
    {
        $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ])->assertOk();

        MercadoLibreCuenta::create([
            'ml_user_id' => 321,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'token-de-acceso-secreto',
            'refresh_token' => 'token-de-renovacion-secreto',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);

        $endpoints = [
            fn () => $this->getJson(route('configuracion.mercadolibre.estado')),
            fn () => $this->getJson(route('configuracion.mercadolibre.pendiente')),
            fn () => $this->getJson(route('configuracion.mercadolibre.operaciones')),
        ];

        foreach ($endpoints as $llamar) {
            $contenido = $llamar()->getContent();
            $this->assertStringNotContainsString('clave-secreta-de-prueba-32chars', $contenido);
            $this->assertStringNotContainsString('token-de-acceso-secreto', $contenido);
            $this->assertStringNotContainsString('token-de-renovacion-secreto', $contenido);
            $this->assertStringNotContainsString('client_secret', $contenido);
            $this->assertStringNotContainsString('access_token', $contenido);
            $this->assertStringNotContainsString('refresh_token', $contenido);
        }
    }

    public function test_cambiar_credenciales_con_cuenta_vinculada_exige_revinculacion(): void
    {
        $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '123456789012',
            'client_secret' => str_repeat('a', 32),
            'site_id' => 'MLA',
        ])->assertOk();

        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 777,
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk',
            'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3),
        ]);

        $response = $this->putJson(route('configuracion.mercadolibre.guardar'), [
            'client_id' => '999999999999',
            'client_secret' => '',
            'site_id' => 'MLA',
        ]);

        $response->assertOk()->assertJsonPath('requiere_revinculacion', true);
        $this->assertSame(EstadoConexion::Caida, $cuenta->fresh()->estado);
    }

    /**
     * T005 (spec 050, US1): persistencia de la Lista de Precios Premium, opcional.
     *
     * spec 084/FR-016: cambiar una lista republica TODOS los precios en Mercado Libre, así que
     * ahora hace falta `confirma_republicacion`. Sin él la respuesta es 422 con el impacto — el
     * caso está cubierto en `RetencionPrecioPantallaTest`. Acá se confirma, porque lo que este
     * test verifica es la persistencia, no el diálogo.
     */
    public function test_guarda_y_borra_la_lista_de_precios_premium(): void
    {
        $listaPremium = ListaPrecio::create(['nombre' => 'ML Premium', 'activo' => true]);

        $response = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id_premium' => $listaPremium->id,
            'confirma_republicacion' => true,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($listaPremium->id, MercadoLibreConfiguracion::actual()->lista_precio_id_premium);

        $estado = $this->getJson(route('configuracion.mercadolibre.estado'));
        $estado->assertOk()->assertJsonPath('configuracion.lista_precio_id_premium', $listaPremium->id);

        $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id_premium' => null,
            'confirma_republicacion' => true,
        ])->assertOk();

        $this->assertNull(MercadoLibreConfiguracion::actual()->lista_precio_id_premium);
    }
}
