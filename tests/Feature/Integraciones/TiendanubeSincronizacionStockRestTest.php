<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
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
 * Spec 024, US2: SincronizadorStock vía el cliente REST — una `PUT
 * /products/{id}/variants/{id}` por vínculo pendiente (research.md R4, sin
 * batch a diferencia de la versión MCP), continuidad ante el rechazo de un
 * vínculo puntual, vínculo incompleto sin llamar a la API, y los mismos
 * cortes de FR-009/FR-010 con un único registro en el historial.
 */
class TiendanubeSincronizacionStockRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false,
        ]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function fakearOk(): void
    {
        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);
    }

    private function crearVinculoPendiente(int $stockInicial = 5, ?string $tnProductId = null): TiendanubeVarianteProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create([
            'variant_id' => $producto->id * 10,
            'tn_product_id' => $tnProductId ?? (string) $producto->id,
            'producto_id' => $producto->id,
        ]);

        app(StockService::class)->ajustar($producto, null, Deposito::first(), $stockInicial, 'carga inicial');
        $vinculo->update(['stock_pendiente' => true]);

        return $vinculo;
    }

    public function test_un_vinculo_pendiente_genera_una_unica_put_con_el_stock_final(): void
    {
        $vinculo = $this->crearVinculoPendiente(10);
        app(StockService::class)->ajustar($vinculo->producto, null, Deposito::first(), 6, 'ajuste 2');
        $vinculo->update(['stock_pendiente' => true]);

        $this->fakearOk();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $resultado['actualizados']);

        Http::assertSent(function ($request) use ($vinculo) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), "products/{$vinculo->tn_product_id}/variants/{$vinculo->variant_id}")
                && $request['stock'] === 16;
        });
    }

    public function test_stock_negativo_se_envia_como_cero(): void
    {
        $vinculo = $this->crearVinculoPendiente(5);
        app(StockService::class)->registrarSalida($vinculo->producto, null, Deposito::first(), 20);
        $vinculo->update(['stock_pendiente' => true]);

        $this->fakearOk();

        app(SincronizadorStock::class)->ejecutar();

        Http::assertSent(fn ($request) => $request['stock'] === 0);
    }

    public function test_exito_deja_el_vinculo_sincronizado_con_fecha(): void
    {
        $vinculo = $this->crearVinculoPendiente();
        $this->fakearOk();

        app(SincronizadorStock::class)->ejecutar();

        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertNotNull($vinculo->stock_sincronizado_en);
        $this->assertNull($vinculo->stock_error);
    }

    public function test_vinculo_con_tn_product_id_vacio_se_senala_sin_llamar_a_la_api(): void
    {
        Http::fake();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => null, 'producto_id' => $producto->id]);
        app(StockService::class)->ajustar($producto, null, Deposito::first(), 5, 'carga inicial');
        $vinculo->update(['stock_pendiente' => true]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        Http::assertNothingSent();
        $this->assertSame(1, $resultado['con_error']);
        $vinculo->refresh();
        $this->assertTrue($vinculo->stock_pendiente);
        $this->assertStringContainsString('Vínculo incompleto', $vinculo->stock_error);
    }

    public function test_varios_vinculos_pendientes_generan_una_put_por_cada_uno(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->crearVinculoPendiente();
        }
        $this->fakearOk();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(5, $resultado['actualizados']);
        Http::assertSentCount(5);
    }

    /** Spec 036 US2 (FR-017, SC-003): un producto con 2 variantes vinculadas envía la misma cantidad a ambas, de forma independiente. */
    public function test_producto_con_dos_variantes_vinculadas_envia_la_misma_cantidad_a_ambas(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = TiendanubeVarianteProducto::create(['variant_id' => 101, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $vinculo2 = TiendanubeVarianteProducto::create(['variant_id' => 102, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, Deposito::first(), 9, 'carga inicial');
        $vinculo1->update(['stock_pendiente' => true]);
        $vinculo2->update(['stock_pendiente' => true]);

        $this->fakearOk();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(2, $resultado['actualizados']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'variants/101') && $request['stock'] === 9);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'variants/102') && $request['stock'] === 9);

        $this->assertFalse($vinculo1->fresh()->stock_pendiente);
        $this->assertFalse($vinculo2->fresh()->stock_pendiente);
    }

    public function test_rechazo_de_un_vinculo_puntual_no_afecta_a_los_demas(): void
    {
        $vinculoOk = $this->crearVinculoPendiente(3);
        $vinculoError = $this->crearVinculoPendiente(3);

        Http::fake([
            "api.tiendanube.com/v1/*/products/*/variants/{$vinculoError->variant_id}" => Http::response(['message' => 'Producto no encontrado'], 404),
            'api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200),
        ]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $resultado['con_error']);

        $this->assertFalse($vinculoOk->fresh()->stock_pendiente);
        $this->assertTrue($vinculoError->fresh()->stock_pendiente);
    }

    public function test_reintento_ante_429_termina_sincronizado_sin_marcar_error(): void
    {
        $vinculo = $this->crearVinculoPendiente();

        Http::fake([
            'api.tiendanube.com/v1/*/products/*/variants/*' => Http::sequence()
                ->push(['message' => 'rate_limited'], 429)
                ->push(['id' => 1], 200),
        ]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        Http::assertSentCount(2);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(0, $resultado['con_error']);
        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertNull($vinculo->stock_error);
    }

    public function test_devuelve_los_contadores_esperados_via_endpoint(): void
    {
        $this->crearVinculoPendiente();
        $this->fakearOk();

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
        $this->assertSame(1, TiendanubeRestOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_modo_solo_lectura_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, TiendanubeRestOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_conexion_caida_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        TiendanubeConexionRest::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, TiendanubeRestOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    /** @dataProvider almacenesDeCache */
    public function test_el_candado_funciona_con_el_almacen_de_cache_indicado(string $store): void
    {
        config(['cache.default' => $store]);
        $this->fakearOk();

        $vinculo = $this->crearVinculoPendiente();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertFalse($vinculo->fresh()->stock_pendiente);
    }

    public static function almacenesDeCache(): array
    {
        return [
            'archivos (hosting compartido)' => ['file'],
            'base de datos (VPS con colas)' => ['database'],
        ];
    }
}
