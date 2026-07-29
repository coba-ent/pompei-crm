<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Rol;
use App\Services\Tiendanube\ClienteTiendanube;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 015): "Probar conexión" contra la API real, panel de estado y
 * "Desconectar". FR-007..FR-011. FR-015: el historial nunca contiene el token.
 */
class TiendanubeConexionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConfiguracion::actual()->update([
            'store_id' => '1234567',
            'access_token' => 'token-vigente-de-prueba',
        ]);
    }

    public function test_probar_conexion_exitosa_actualiza_los_datos_de_la_tienda(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/1234567/store' => Http::response([
                'id' => 1234567,
                'name' => ['es' => 'Mi Tienda'],
                'original_domain' => 'mitienda.mitiendanube.com',
                'country' => 'AR',
                'currency' => 'ARS',
            ], 200),
        ]);

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('estado', EstadoConexion::Conectada->value)
            ->assertJsonPath('tienda.nombre', 'Mi Tienda')
            ->assertJsonPath('tienda.dominio', 'mitienda.mitiendanube.com')
            ->assertJsonPath('tienda.pais', 'AR')
            ->assertJsonPath('tienda.moneda', 'ARS');

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame(EstadoConexion::Conectada, $configuracion->estado);
        $this->assertNotNull($configuracion->ultima_verificacion_en);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authentication', 'bearer token-vigente-de-prueba')
                && $request->hasHeader('User-Agent')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_probar_conexion_con_token_invalido_no_queda_conectada(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'invalid_token'], 401)]);

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertOk()->assertJsonPath('ok', false);

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame(EstadoConexion::Caida, $configuracion->estado);
        $this->assertNotNull($configuracion->ultimo_error);
    }

    public function test_probar_conexion_con_store_id_incorrecto_devuelve_404_y_marca_caida(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'not_found'], 404)]);

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertOk()->assertJsonPath('ok', false);
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_desconectar_borra_el_token_conserva_los_datos_de_tienda_y_queda_en_el_historial(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'estado' => EstadoConexion::Conectada,
            'nombre_tienda' => 'Mi Tienda',
            'dominio' => 'mitienda.mitiendanube.com',
            'pais' => 'AR',
            'moneda' => 'ARS',
        ]);

        $response = $this->postJson(route('configuracion.tiendanube.desconectar'));

        $response->assertOk()->assertJsonPath('ok', true);

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertNull($configuracion->access_token);
        $this->assertSame(EstadoConexion::Desconectada, $configuracion->estado);
        $this->assertSame('Mi Tienda', $configuracion->nombre_tienda);
        $this->assertSame('mitienda.mitiendanube.com', $configuracion->dominio);

        $this->assertTrue(TiendanubeOperacionLog::where('operacion', 'desconectar')->exists());
    }

    /**
     * Regresión: estaCompleta() (usada para el gate de FR-004) no debe ser el
     * criterio de "no_configurada" en /estado — si lo fuera, Desconectar (que
     * borra access_token) colapsaría siempre a "no_configurada" en vez de
     * "Desconectada", ocultando el store_id y los datos de tienda que FR-011
     * exige conservar.
     */
    public function test_tras_desconectar_el_estado_es_desconectada_no_no_configurada(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'estado' => EstadoConexion::Conectada,
            'nombre_tienda' => 'Mi Tienda',
            'dominio' => 'mitienda.mitiendanube.com',
        ]);

        $this->postJson(route('configuracion.tiendanube.desconectar'))->assertOk();

        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('estado', EstadoConexion::Desconectada->value)
            ->assertJsonPath('configuracion.store_id', '1234567')
            ->assertJsonPath('configuracion.token_cargado', false)
            ->assertJsonPath('tienda.nombre', 'Mi Tienda');
    }

    public function test_ningun_dato_sensible_aparece_en_el_historial(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'invalid_token'], 401)]);

        app(ClienteTiendanube::class)->probarConexion();

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertNotNull($registro);
        $this->assertStringNotContainsString('token-vigente-de-prueba', (string) $registro->mensaje_error);
        $this->assertStringNotContainsString('token-vigente-de-prueba', (string) $registro->payload_bloqueado);

        $contenido = $this->getJson(route('configuracion.tiendanube.historial'))->getContent();
        $this->assertStringNotContainsString('token-vigente-de-prueba', $contenido);
    }
}
