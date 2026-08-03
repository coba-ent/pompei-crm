<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1 (spec 013): consolidación a un único envío (FR-003, SC-003), piso en
 * cero (FR-004, SC-004), sincronizado con fecha (FR-017). Los cortes de
 * FR-009/FR-010 y la continuidad ante un rechazo puntual (FR-014/FR-015) se
 * cubren en detalle en MercadoLibreSincronizarStockTest / T028-T029.
 */
class SincronizadorStockTest extends TestCase
{
    use RefreshDatabase;

    protected Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente',
            'refresh_token' => 'rtk-vigente',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);

        $this->deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_varias_ventas_seguidas_generan_un_unico_envio_con_el_valor_final(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $stock = app(StockService::class);
        $stock->ajustar($producto, null, $this->deposito, 10, 'carga inicial');
        $stock->registrarSalida($producto, null, $this->deposito, 3);
        $stock->registrarSalida($producto, null, $this->deposito, 2);
        $vinculo->update(['stock_pendiente' => true]);

        $llamadas = 0;
        Http::fake(function ($request) use (&$llamadas) {
            $llamadas++;

            return Http::response(['id' => 'MLA1', 'available_quantity' => $request->data()['available_quantity']], 200);
        });

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $llamadas, 'Debe haber un único PUT /items/... para el producto, no uno por movimiento.');

        Http::assertSent(function ($request) {
            return $request->data()['available_quantity'] === 5;
        });

        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertNotNull($vinculo->stock_sincronizado_en);
    }

    public function test_stock_negativo_se_envia_como_cero(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'stock_pendiente' => true]);

        app(StockService::class)->registrarSalida($producto, null, $this->deposito, 5);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        app(SincronizadorStock::class)->ejecutar();

        Http::assertSent(function ($request) {
            return $request->data()['available_quantity'] === 0;
        });

        $this->assertFalse($vinculo->fresh()->stock_pendiente);
    }

    public function test_sin_vinculos_pendientes_no_envia_nada(): void
    {
        Http::fake();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(0, $resultado['actualizados']);
        Http::assertNothingSent();
    }

    /** T028 (US4, FR-014/FR-015, SC-006): el rechazo de un vínculo no interrumpe el resto de la corrida. */
    public function test_el_rechazo_de_un_vinculo_no_interrumpe_el_resto(): void
    {
        $productoOk = Producto::factory()->create(['tipo' => 'producto']);
        $vinculoOk = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-OK', 'producto_id' => $productoOk->id, 'stock_pendiente' => true]);

        $productoPausado = Producto::factory()->create(['tipo' => 'producto']);
        $vinculoPausado = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-PAUSADO', 'producto_id' => $productoPausado->id, 'stock_pendiente' => true]);

        $stock = app(StockService::class);
        $stock->ajustar($productoOk, null, $this->deposito, 5, 'carga inicial');
        $stock->ajustar($productoPausado, null, $this->deposito, 5, 'carga inicial');

        Http::fake([
            'api.mercadolibre.com/items/MLA-PAUSADO' => Http::response(['message' => 'item_paused'], 400),
            'api.mercadolibre.com/items/MLA-OK' => Http::response(['id' => 'MLA-OK'], 200),
        ]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $resultado['con_error']);

        $vinculoOk->refresh();
        $this->assertFalse($vinculoOk->stock_pendiente);
        $this->assertNull($vinculoOk->stock_error);

        $vinculoPausado->refresh();
        $this->assertTrue($vinculoPausado->stock_pendiente, 'El vínculo con error debe seguir pendiente para reintentar.');
        $this->assertNotNull($vinculoPausado->stock_error);
        $this->assertNotNull($vinculoPausado->stock_error_en);
    }

    /** Spec 036 US2 (FR-007, SC-003): un producto con 2 publicaciones vinculadas envía la misma cantidad a ambas, de forma independiente. */
    public function test_producto_con_dos_publicaciones_vinculadas_envia_la_misma_cantidad_a_ambas(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $vinculo2 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA2', 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, $this->deposito, 7, 'carga inicial');
        $vinculo1->update(['stock_pendiente' => true]);
        $vinculo2->update(['stock_pendiente' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'ok'], 200)]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(2, $resultado['actualizados']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'MLA1') && $request->data()['available_quantity'] === 7);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'MLA2') && $request->data()['available_quantity'] === 7);

        $this->assertFalse($vinculo1->fresh()->stock_pendiente);
        $this->assertFalse($vinculo2->fresh()->stock_pendiente);
    }

    /** T029 (FR-013): el reintento ante 429/5xx lo cubre ClienteMercadoLibre, sin código propio. */
    public function test_reintenta_ante_429_y_termina_sincronizado(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'stock_pendiente' => true]);
        app(StockService::class)->ajustar($producto, null, $this->deposito, 5, 'carga inicial');

        $intentos = 0;
        Http::fake(function () use (&$intentos) {
            $intentos++;

            if ($intentos === 1) {
                return Http::response(['message' => 'too many requests'], 429);
            }

            return Http::response(['id' => 'MLA1'], 200);
        });

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(2, $intentos);

        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertNull($vinculo->stock_error);
    }

    /** T030: ningún dato sensible llega al historial — reutiliza el saneado de ClienteMercadoLibre::registrarLog() (spec 011). */
    public function test_ningun_dato_sensible_llega_al_historial(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'stock_pendiente' => true]);
        app(StockService::class)->ajustar($producto, null, $this->deposito, 5, 'carga inicial');

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        app(SincronizadorStock::class)->ejecutar();

        $log = \DB::table('ml_operaciones_log')->where('operacion', 'sincronizar_stock')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('atk-vigente', json_encode($log));
        $this->assertStringNotContainsString('access_token', json_encode($log));
    }
}
