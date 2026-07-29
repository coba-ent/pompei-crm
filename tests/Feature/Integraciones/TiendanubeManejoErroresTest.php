<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use App\Services\Tiendanube\ClienteTiendanube;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US4 (spec 015): manejo de errores de contracts/api-tiendanube.md §4,
 * implementado una única vez en ClienteTiendanube (research.md §R5). Sin
 * renovación de token (a diferencia de Mercado Libre): 401/403/404 marcan
 * "Caída" directamente, sin ningún reintento.
 */
class TiendanubeManejoErroresTest extends TestCase
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
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    public function test_401_marca_caida_con_ultimo_error_descriptivo_y_no_reintenta(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'invalid_token'], 401)]);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(1); // sin reintento
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
        $this->assertNotNull(TiendanubeConfiguracion::actual()->ultimo_error);
    }

    public function test_404_sobre_store_recibe_el_mismo_tratamiento_que_401(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'not_found'], 404)]);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(1);
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
        $this->assertStringContainsString('identificador de tienda', TiendanubeConfiguracion::actual()->ultimo_error);
    }

    public function test_tras_recargar_un_token_valido_probar_conexion_vuelve_a_conectar(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/1234567/store' => Http::sequence()
                ->push(['message' => 'invalid_token'], 401)
                ->push(['id' => 1234567, 'name' => ['es' => 'Mi Tienda'], 'original_domain' => 'x.mitiendanube.com', 'country' => 'AR', 'currency' => 'ARS'], 200),
        ]);

        app(ClienteTiendanube::class)->probarConexion();
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);

        TiendanubeConfiguracion::actual()->update(['access_token' => 'token-nuevo-valido']);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->exito);
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_429_aplica_espera_creciente_hasta_3_reintentos(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response(['message' => 'rate_limited'], 429)]);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(4); // intento original + 3 reintentos
        // 429 no es un rechazo de credencial: no marca la conexión como caída.
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_5xx_reintenta_sin_marcar_la_conexion_como_caida(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/1234567/store' => Http::sequence()
                ->push(['message' => 'internal'], 500)
                ->push(['message' => 'internal'], 500)
                ->push(['id' => 1234567, 'name' => ['es' => 'Mi Tienda'], 'original_domain' => 'x.mitiendanube.com', 'country' => 'AR', 'currency' => 'ARS'], 200),
        ]);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->exito);
        Http::assertSentCount(3);
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_credenciales_ilegibles_marca_caida_sin_llamar_a_la_api(): void
    {
        // Simula que el access_token cifrado quedó ilegible (APP_KEY cambió): se
        // escribe directo en la columna un valor que Crypt no puede descifrar.
        \DB::table('tn_configuracion')->where('id', TiendanubeConfiguracion::actual()->id)
            ->update(['access_token' => 'valor-no-cifrado-ilegible']);

        Http::fake();

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        $this->assertTrue($respuesta->fallo());
        Http::assertNothingSent();
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
    }
}
