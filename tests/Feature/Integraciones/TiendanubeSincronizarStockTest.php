<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Stock\StockService;
use App\Services\Tiendanube\SincronizadorStock;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 018): "Sincronizar stock ahora" — contracts §1. No concurrencia
 * (FR-008) y los tres cortes de FR-009/FR-010 con un único registro en el
 * historial (research.md R7), no uno por vínculo pendiente.
 */
class TiendanubeSincronizarStockTest extends TestCase
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
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false,
        ]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function crearVinculoPendiente(): TiendanubeVarianteProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create([
            'variant_id' => $producto->id * 10,
            'tn_product_id' => (string) $producto->id,
            'producto_id' => $producto->id,
        ]);

        app(StockService::class)->ajustar($producto, null, Deposito::first(), 5, 'carga inicial');
        $vinculo->update(['stock_pendiente' => true]);

        return $vinculo;
    }

    public function test_devuelve_los_contadores_esperados(): void
    {
        $this->crearVinculoPendiente();
        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'actualizados' => 1, 'con_error' => 0]);
    }

    public function test_dos_disparos_simultaneos_solo_ejecutan_uno(): void
    {
        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    public function test_bloqueada_por_funcion_desactivada_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, TiendanubeOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_modo_solo_lectura_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, TiendanubeOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_conexion_caida_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        TiendanubeConfiguracion::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, TiendanubeOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }
}
