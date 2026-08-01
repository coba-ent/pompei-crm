<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
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

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente-de-prueba', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false, 'creacion_automatica' => true,
        ]);
        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_orden_convertida_no_dispara_un_envio_de_stock_en_la_corrida_siguiente(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        Http::fake([
            'api.tiendanube.com/v1/*/orders*' => Http::response([[
                'id' => 1001, 'status' => 'open', 'payment_status' => 'paid', 'shipping_status' => 'unpacked',
                'completed_at' => now()->toIso8601String(), 'total' => 500.0, 'currency' => 'ARS',
                'storefront' => 'store',
                'contact_email' => 'comprador@test.com', 'contact_name' => 'Comprador', 'contact_identification' => '',
                'products' => [[
                    'product_id' => 10, 'variant_id' => 1, 'name' => 'Producto',
                    'variant_values' => [], 'quantity' => 1, 'price' => 500.0,
                ]],
            ]], 200),
        ]);

        $this->artisan('tiendanube:sincronizar-ordenes --forzar')->assertExitCode(0);

        $vinculo = TiendanubeVarianteProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente, 'La conversión automática no debe marcar el vínculo pendiente.');

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $this->artisan('tiendanube:sincronizar-stock --forzar')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
