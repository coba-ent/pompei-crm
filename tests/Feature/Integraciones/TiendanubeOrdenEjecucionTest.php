<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 018, FR-006, research.md R4): ejecutar
 * tiendanube:sincronizar-ordenes seguido de tiendanube:sincronizar-stock no
 * genera ningún envío de stock por las órdenes recién convertidas — el
 * segundo comando ya ve el vínculo sin pendiente, sin necesitar una segunda
 * corrida.
 */
class TiendanubeOrdenEjecucionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false, 'creacion_automatica' => true,
        ]);
        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConfiguracion::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_orden_convertida_no_dispara_un_envio_de_stock_en_la_corrida_siguiente(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => ['orders' => [[
                    'id' => 1001, 'status' => 'open', 'payment_status' => 'paid', 'fulfillment_status' => 'unpacked',
                    'completed_at' => now()->toIso8601String(), 'total' => ['amount' => 500.0, 'currency' => 'ARS'],
                    'storefront' => 'store',
                    'customer' => ['id' => 900, 'email' => 'comprador@test.com', 'name' => 'Comprador', 'cpf_cnpj' => null],
                    'items' => [[
                        'product_id' => 10, 'variant_id' => 1, 'name' => 'Producto',
                        'variant_values' => [], 'quantity' => 1, 'price' => ['amount' => 500.0, 'currency' => 'ARS'],
                    ]],
                ]]]],
            ], 200),
        ]);

        $this->artisan('tiendanube:sincronizar-ordenes --forzar')->assertExitCode(0);

        $vinculo = TiendanubeVarianteProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente, 'La conversión automática no debe marcar el vínculo pendiente.');

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);

        $this->artisan('tiendanube:sincronizar-stock --forzar')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
