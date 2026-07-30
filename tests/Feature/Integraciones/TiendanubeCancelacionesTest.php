<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use App\Services\Tiendanube\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** US6 (spec 017): cancelaciones y reembolsos posteriores. FR-057/FR-058/FR-059. */
class TiendanubeCancelacionesTest extends TestCase
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
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $cuenta = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConfiguracion::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);
    }

    private function ordenCruda(int $id, string $status, string $paymentStatus, int $variantId = 1): array
    {
        return [
            'id' => $id, 'status' => $status, 'payment_status' => $paymentStatus, 'fulfillment_status' => 'unpacked',
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

    public function test_orden_convertida_que_se_cancela_no_modifica_la_venta_y_queda_senalada(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create([
            'tn_order_id' => 7001, 'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.0, 'moneda' => 'ARS',
            'comprador_email' => 'comprador@test.com', 'sincronizada_en' => now(),
        ]);
        $orden->items()->create([
            'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto', 'cantidad' => 1,
            'precio_unitario' => 1210.0, 'total_linea' => 1210.0, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $venta = $resultado['venta'];
        $stockAntes = (float) \App\Models\Stock::where('producto_id', $producto->id)->value('cantidad');

        $this->fakearListado([$this->ordenCruda(7001, 'cancelled', 'paid')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertNotNull($orden->venta_id);

        // La Venta permanece intacta: no se borró, no cambió su total, el stock no se revirtió.
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
        $this->assertSame(1210.0, (float) $venta->fresh()->total);
        $this->assertSame($stockAntes, (float) \App\Models\Stock::where('producto_id', $producto->id)->value('cantidad'));
    }

    public function test_orden_convertida_reembolsada_tambien_queda_senalada_sin_tocar_la_venta(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 2, 'producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create([
            'tn_order_id' => 7002, 'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.0, 'moneda' => 'ARS',
            'comprador_email' => 'comprador2@test.com', 'sincronizada_en' => now(),
        ]);
        $orden->items()->create([
            'tn_product_id' => 10, 'variant_id' => 2, 'nombre_producto' => 'Producto', 'cantidad' => 1,
            'precio_unitario' => 1210.0, 'total_linea' => 1210.0, 'producto_id' => $producto->id,
        ]);

        app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->fakearListado([$this->ordenCruda(7002, 'closed', 'refunded', 2)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertSame('cancelada', $orden->fresh()->estado_conversion->value);
        $this->assertNotNull($orden->fresh()->venta_id);
    }

    public function test_orden_no_convertida_que_se_cancela_deshabilita_la_conversion(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->fakearListado([$this->ordenCruda(7003, 'cancelled', 'pending')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden = TiendanubeOrden::where('tn_order_id', 7003)->firstOrFail();
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertFalse($orden->estado_conversion->habilitaCrearVenta());

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: false);
        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseCount('ventas', 0);
    }
}
