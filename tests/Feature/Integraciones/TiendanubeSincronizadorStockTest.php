<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Stock\StockService;
use App\Services\Tiendanube\SincronizadorStock;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1 (spec 018): SincronizadorStock — consolidación a un único envío por
 * vínculo (FR-003, SC-003), piso en cero (FR-004, SC-004), vínculo incompleto
 * sin llamar a la API (FR-005a) y loteo de hasta 50 por llamada (research.md R6).
 */
class TiendanubeSincronizadorStockTest extends TestCase
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

    private function fakearOk(): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);
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

    public function test_varias_ventas_seguidas_generan_una_unica_entrada_en_el_lote_con_el_valor_final(): void
    {
        $vinculo = $this->crearVinculoPendiente(10);
        // Simula varios movimientos consolidados: el stock final ya refleja todos (10 + 6 = 16).
        app(StockService::class)->ajustar($vinculo->producto, null, Deposito::first(), 6, 'ajuste 2');
        $vinculo->update(['stock_pendiente' => true]);

        $this->fakearOk();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, $resultado['actualizados']);

        Http::assertSent(function ($request) use ($vinculo) {
            $updates = $request['params']['arguments']['updates'] ?? [];

            return $request['params']['name'] === 'update_stock_and_price'
                && count($updates) === 1
                && $updates[0]['variant_id'] === $vinculo->variant_id
                && $updates[0]['stock'] === 16;
        });
    }

    public function test_stock_negativo_se_envia_como_cero(): void
    {
        $vinculo = $this->crearVinculoPendiente(5);
        app(StockService::class)->registrarSalida($vinculo->producto, null, Deposito::first(), 20);
        $vinculo->update(['stock_pendiente' => true]);

        $this->fakearOk();

        app(SincronizadorStock::class)->ejecutar();

        Http::assertSent(function ($request) {
            $updates = $request['params']['arguments']['updates'] ?? [];

            return isset($updates[0]) && $updates[0]['stock'] === 0;
        });
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

    public function test_vinculo_con_tn_product_id_vacio_se_senala_sin_entrar_al_lote(): void
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

    public function test_con_mas_de_50_vinculos_pendientes_se_envian_dos_llamadas(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->crearVinculoPendiente();
        }
        $this->fakearOk();

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(55, $resultado['actualizados']);
        Http::assertSentCount(2);
    }

    public function test_rechazo_de_un_vinculo_puntual_no_afecta_al_resto_del_chunk(): void
    {
        $vinculoOk = $this->crearVinculoPendiente(3);
        $vinculoError = $this->crearVinculoPendiente(3);

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => [
                    'results' => [
                        ['variant_id' => $vinculoError->variant_id, 'success' => false, 'error' => 'Producto no encontrado'],
                    ],
                ]],
            ], 200),
        ]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $resultado['con_error']);

        $this->assertFalse($vinculoOk->fresh()->stock_pendiente);
        $this->assertTrue($vinculoError->fresh()->stock_pendiente);
        $this->assertSame('Producto no encontrado', $vinculoError->fresh()->stock_error);
    }

    /**
     * FR-013: SincronizadorStock no implementa reintento propio — lo cubre
     * ClienteTiendanube::ejecutarConReintentos() (research.md R7). Una secuencia
     * 429→200 termina con el vínculo sincronizado, sin marcarlo como error.
     */
    public function test_reintento_ante_429_termina_sincronizado_sin_marcar_error(): void
    {
        $vinculo = $this->crearVinculoPendiente();

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::sequence()
                ->push(['message' => 'rate_limited'], 429)
                ->push([
                    'jsonrpc' => '2.0', 'id' => 1,
                    'result' => ['isError' => false, 'structuredContent' => []],
                ], 200),
        ]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        Http::assertSentCount(2);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(0, $resultado['con_error']);
        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertNull($vinculo->stock_error);
    }
}
