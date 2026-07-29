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
 * FR-006b (spec 015): con la función "Tiendanube" desactivada en Funciones
 * Avanzadas, toda operación (lectura y escritura) se rechaza y se registra
 * como bloqueada, sin alterar el estado de la conexión.
 */
class TiendanubeFuncionDesactivadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        // La función queda desactivada (default del seeder): no se hace ->update(['activa' => true]).

        TiendanubeConfiguracion::actual()->update([
            'store_id' => '1234567',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    public function test_toda_operacion_se_rechaza_sin_alterar_el_estado_de_la_conexion(): void
    {
        Http::fake();

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        Http::assertNothingSent();
        $this->assertTrue($respuesta->fueBloqueada());
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertSame('bloqueada', $registro->resultado);
    }

    public function test_probar_conexion_desde_la_pantalla_de_configuracion_omite_el_guard(): void
    {
        // El endpoint HTTP "probar" pasa omitir_guard_funcion: true — permite
        // verificar credenciales aunque la función todavía no esté activa.
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response([
            'id' => 1234567, 'name' => ['es' => 'Mi Tienda'], 'original_domain' => 'x.mitiendanube.com',
            'country' => 'AR', 'currency' => 'ARS',
        ], 200)]);

        $response = $this->postJson(route('configuracion.tiendanube.probar'));

        $response->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);
    }
}
