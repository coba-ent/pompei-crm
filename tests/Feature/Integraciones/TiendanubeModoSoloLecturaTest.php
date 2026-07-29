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
 * US3 (spec 015): kill-switch de sólo lectura (SC-003) e historial. La
 * verificación vive en un único punto (ClienteTiendanube::peticion()).
 */
class TiendanubeModoSoloLecturaTest extends TestCase
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

    public function test_con_el_modo_activo_ninguna_escritura_alcanza_a_tiendanube(): void
    {
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(); // cualquier request real sería un bug.

        $respuesta = app(ClienteTiendanube::class)->enviar('actualizar_producto', 'PUT', '/1234567/products/1', ['name' => 'Producto de prueba']);

        Http::assertNothingSent();
        $this->assertTrue($respuesta->fueBloqueada());
        $this->assertTrue($respuesta->fallo());

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertSame('bloqueada', $registro->resultado);
        $this->assertNotNull($registro->payload_bloqueado);
        $this->assertStringContainsString('Producto de prueba', $registro->payload_bloqueado);
    }

    public function test_las_lecturas_siguen_funcionando_con_el_modo_activo(): void
    {
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(['api.tiendanube.com/v1/1234567/store' => Http::response([
            'id' => 1234567, 'name' => ['es' => 'Mi Tienda'], 'original_domain' => 'x.mitiendanube.com',
            'country' => 'AR', 'currency' => 'ARS',
        ], 200)]);

        $respuesta = app(ClienteTiendanube::class)->probarConexion();

        Http::assertSentCount(1);
        $this->assertTrue($respuesta->exito);
    }

    public function test_el_cambio_del_interruptor_tiene_efecto_inmediato(): void
    {
        Http::fake(['api.tiendanube.com/v1/1234567/products' => Http::response(['id' => 1], 201)]);

        $respuesta1 = app(ClienteTiendanube::class)->enviar('crear_producto', 'POST', '/1234567/products', []);
        $this->assertFalse($respuesta1->fueBloqueada());

        $this->patchJson(route('configuracion.tiendanube.modoSoloLectura'), ['activo' => true])
            ->assertOk()->assertJsonPath('modo_solo_lectura', true);

        $respuesta2 = app(ClienteTiendanube::class)->enviar('crear_producto', 'POST', '/1234567/products', []);
        $this->assertTrue($respuesta2->fueBloqueada());
    }

    public function test_la_retencion_no_borra_registros_dentro_de_la_ventana(): void
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'probar_conexion', 'metodo' => 'GET', 'endpoint' => '/1234567/store',
            'sentido' => 'lectura', 'resultado' => 'exito', 'codigo_http' => 200, 'duracion_ms' => 100,
            'created_at' => now()->subDays(10),
        ]);
        TiendanubeOperacionLog::registrar([
            'operacion' => 'probar_conexion', 'metodo' => 'GET', 'endpoint' => '/1234567/store',
            'sentido' => 'lectura', 'resultado' => 'exito', 'codigo_http' => 200, 'duracion_ms' => 100,
            'created_at' => now()->subDays(40), // fuera de la ventana de 30 días
        ]);

        $reflexion = new \ReflectionMethod(TiendanubeOperacionLog::class, 'depurarPorRetencion');
        $reflexion->setAccessible(true);
        $reflexion->invoke(null);

        $this->assertDatabaseHas('tn_operaciones_log', ['duracion_ms' => 100, 'created_at' => now()->subDays(10)->toDateTimeString()]);
        $this->assertSame(1, TiendanubeOperacionLog::count());
    }
}
