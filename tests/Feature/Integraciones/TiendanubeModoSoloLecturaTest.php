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
 * US3 (spec 019, sin cambios de intención respecto de spec 015): kill-switch
 * de sólo lectura (FR-012) e historial, re-verificados contra el
 * ClienteTiendanube basado en MCP (research.md §R2). La verificación vive en
 * un único punto (ClienteTiendanube::peticion()).
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
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    public function test_con_el_modo_activo_ninguna_escritura_alcanza_a_tiendanube(): void
    {
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(); // cualquier request real sería un bug.

        $respuesta = app(ClienteTiendanube::class)->escribir('update_stock_and_price', ['product_id' => 1, 'stock' => 5]);

        Http::assertNothingSent();
        $this->assertTrue($respuesta->fueBloqueada());
        $this->assertTrue($respuesta->fallo());

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertSame('bloqueada', $registro->resultado);
        $this->assertNotNull($registro->payload_bloqueado);
        $this->assertStringContainsString('product_id', $registro->payload_bloqueado);
    }

    public function test_las_lecturas_siguen_funcionando_con_el_modo_activo(): void
    {
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['isError' => false, 'structuredContent' => ['pagination' => ['total_elements' => 10], 'products' => []]],
        ], 200)]);

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1, 'page_size' => 1]);

        Http::assertSentCount(1);
        $this->assertTrue($respuesta->exito);
    }

    public function test_el_cambio_del_interruptor_tiene_efecto_inmediato(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['isError' => false, 'structuredContent' => ['id' => 1]],
        ], 200)]);

        $respuesta1 = app(ClienteTiendanube::class)->escribir('update_stock_and_price', ['product_id' => 1, 'stock' => 5]);
        $this->assertFalse($respuesta1->fueBloqueada());

        $this->patchJson(route('configuracion.tiendanube.modoSoloLectura'), ['activo' => true])
            ->assertOk()->assertJsonPath('modo_solo_lectura', true);

        $respuesta2 = app(ClienteTiendanube::class)->escribir('update_stock_and_price', ['product_id' => 1, 'stock' => 5]);
        $this->assertTrue($respuesta2->fueBloqueada());
    }

    public function test_la_retencion_no_borra_registros_dentro_de_la_ventana(): void
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'list_products', 'metodo' => 'POST', 'endpoint' => '/',
            'sentido' => 'lectura', 'resultado' => 'exito', 'codigo_http' => 200, 'duracion_ms' => 100,
            'created_at' => now()->subDays(10),
        ]);
        TiendanubeOperacionLog::registrar([
            'operacion' => 'list_products', 'metodo' => 'POST', 'endpoint' => '/',
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
