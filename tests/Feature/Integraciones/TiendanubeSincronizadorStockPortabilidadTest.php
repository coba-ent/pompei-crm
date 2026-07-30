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
 * FR-011: SincronizadorStock reutiliza `Cache::lock()` sin ninguna rama de
 * código específica por entorno — el mismo mecanismo de portabilidad que
 * SincronizadorOrdenes (spec 017). Se ejercita el candado con los dos
 * almacenes de caché soportados en el hosting real del negocio.
 */
class TiendanubeSincronizadorStockPortabilidadTest extends TestCase
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

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);
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

    /** @dataProvider almacenesDeCache */
    public function test_el_candado_funciona_con_el_almacen_de_cache_indicado(string $store): void
    {
        config(['cache.default' => $store]);

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
