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
use App\Services\Tiendanube\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** US5 (spec 017): creación automática de ventas. FR-051/FR-052/FR-053/FR-055/FR-056, SC-005. */
class TiendanubeCreacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private CuentaTesoreria $cuentaTesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConfiguracion::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConfiguracion::actual()->update(['cuenta_tesoreria_id' => $this->cuentaTesoreria->id]);
    }

    private function ordenCruda(int $id, int $variantId = 1): array
    {
        return [
            'id' => $id, 'status' => 'closed', 'payment_status' => 'paid', 'fulfillment_status' => 'unpacked',
            'completed_at' => now()->toIso8601String(), 'total' => ['amount' => 1210.0, 'currency' => 'ARS'],
            'storefront' => 'store',
            'customer' => ['id' => 900 + $id, 'email' => "comprador{$id}@test.com", 'name' => 'Comprador', 'cpf_cnpj' => null],
            'items' => [[
                'product_id' => 10, 'variant_id' => $variantId, 'name' => 'Producto',
                'variant_values' => [], 'quantity' => 1, 'price' => ['amount' => 1210.0, 'currency' => 'ARS'],
            ]],
        ];
    }

    private function fakearListado(array $ordenes): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => ['orders' => $ordenes]],
            ], 200),
        ]);
    }

    public function test_orden_resoluble_se_convierte_sola_con_la_creacion_automatica_activa(): void
    {
        TiendanubeConfiguracion::actual()->update(['creacion_automatica' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->fakearListado([$this->ordenCruda(1001)]);

        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 1001, 'estado_conversion' => 'convertida', 'creacion_automatica' => true]);
    }

    public function test_orden_sin_vincular_no_crea_venta_ni_mueve_stock_y_queda_requiere_atencion(): void
    {
        TiendanubeConfiguracion::actual()->update(['creacion_automatica' => true]);

        $this->fakearListado([$this->ordenCruda(1002, 999)]);

        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseHas('tn_ordenes', [
            'tn_order_id' => 1002, 'estado_conversion' => 'requiere_atencion', 'motivo' => 'variante_sin_vincular',
        ]);
    }

    public function test_resolver_el_motivo_vuelve_a_dejar_la_orden_convertible(): void
    {
        TiendanubeConfiguracion::actual()->update(['creacion_automatica' => false]);

        $this->fakearListado([$this->ordenCruda(1003, 998)]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 1003, 'estado_conversion' => 'requiere_atencion']);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 998, 'producto_id' => $producto->id]);

        $this->fakearListado([$this->ordenCruda(1003, 998)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 1003, 'estado_conversion' => 'lista']);
    }

    public function test_interruptor_apagado_no_crea_ninguna_venta(): void
    {
        TiendanubeConfiguracion::actual()->update(['creacion_automatica' => false]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->fakearListado([$this->ordenCruda(1004)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 1004, 'estado_conversion' => 'lista']);
    }

    /** Un fallo a mitad de camino no deja Venta, cobranza ni movimiento de stock huérfanos. */
    public function test_fallo_durante_la_conversion_automatica_no_deja_venta_parcial(): void
    {
        TiendanubeConfiguracion::actual()->update(['creacion_automatica' => true]);
        // Sin cuenta de Tesorería visible → ConversorOrdenAVenta rechaza antes de crear nada.
        $this->cuentaTesoreria->update(['visible' => false]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->fakearListado([$this->ordenCruda(1005)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('cobros', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 1005]);
    }
}
