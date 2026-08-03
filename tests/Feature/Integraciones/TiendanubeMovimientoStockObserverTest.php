<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Stock\StockService;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US1 (spec 018, FR-001/FR-005) y US2 (FR-002): qué movimientos marcan un
 * vínculo como pendiente de sincronizar hacia Tiendanube, y cuáles no —
 * calcado del equivalente de Mercado Libre (spec 013).
 */
class TiendanubeMovimientoStockObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVentaConProducto(Producto $producto, float $cantidad = 3): Venta
    {
        $cliente = Cliente::factory()->create();

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-30',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertCreated();

        return Venta::findOrFail($respuesta->json('venta.id'));
    }

    public function test_venta_manual_sobre_producto_vinculado_marca_pendiente(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    public function test_movimiento_en_otro_deposito_no_marca_pendiente(): void
    {
        $depositoDefault = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $depositoTn = Deposito::create(['nombre' => 'Depósito TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['deposito_id' => $depositoTn->id]);

        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        // Venta manual usa siempre el depósito por defecto del CRM (primero activo por id),
        // que acá es $depositoDefault — distinto del configurado para Tiendanube.
        $this->crearVentaConProducto($producto, 2);

        $this->assertFalse($vinculo->fresh()->stock_pendiente);
    }

    public function test_producto_sin_vinculo_no_marca_nada(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $this->crearVentaConProducto($producto, 1);

        $this->assertDatabaseCount('tn_variante_producto', 0);
    }

    public function test_ajuste_manual_de_stock_tambien_marca_pendiente(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'ajuste de prueba');

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-015): un producto con 2 variantes vinculadas marca AMBAS pendientes. */
    public function test_producto_con_dos_variantes_vinculadas_marca_ambas_pendientes(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $vinculo2 = TiendanubeVarianteProducto::create(['variant_id' => 2, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo1->fresh()->stock_pendiente);
        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-009), hallazgo T016b: desvincular una variante no afecta a las demás vinculadas al mismo producto. */
    public function test_desvincular_una_variante_no_afecta_a_las_demas(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $vinculo2 = TiendanubeVarianteProducto::create(['variant_id' => 2, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $vinculo1->delete();

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
        $this->assertDatabaseMissing('tn_variante_producto', ['id' => $vinculo1->id]);
    }

    public function test_marcar_pendiente_de_tiendanube_no_interfiere_con_el_vinculo_de_mercadolibre(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculoTn = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $vinculoMl = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 1);

        $this->assertTrue($vinculoTn->fresh()->stock_pendiente);
        $this->assertTrue($vinculoMl->fresh()->stock_pendiente);
    }

    /** ---- US2: exclusión de bucle (FR-002) ---- */

    private function convertirOrdenTiendanube(Producto $producto, float $cantidad = 1): Venta
    {
        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
        ]);
        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);

        TiendanubeVarianteProducto::firstOrCreate(
            ['variant_id' => 1],
            ['tn_product_id' => '10', 'producto_id' => $producto->id]
        );

        $precioUnitario = 605.00;
        $orden = TiendanubeOrden::create([
            'tn_order_id' => random_int(1000000, 9999999), 'status' => 'open', 'payment_status' => 'paid',
            'fulfillment_status' => 'unpacked', 'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => $precioUnitario * $cantidad, 'moneda' => 'ARS', 'tn_customer_id' => 1,
            'comprador_email' => 'comprador@test.com', 'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => '10', 'variant_id' => 1, 'nombre_producto' => 'Producto',
            'cantidad' => $cantidad, 'precio_unitario' => $precioUnitario, 'total_linea' => $precioUnitario * $cantidad,
            'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    public function test_convertir_orden_de_tiendanube_no_marca_pendiente_el_vinculo(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenTiendanube($producto, 2);

        $vinculo = TiendanubeVarianteProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente);
    }

    public function test_venta_manual_sobre_mismo_producto_si_marca_pendiente_tras_una_orden_tn(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenTiendanube($producto, 2);

        $vinculo = TiendanubeVarianteProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->fresh()->stock_pendiente, 'La orden de Tiendanube no debe marcar pendiente.');

        $this->crearVentaConProducto($producto, 1);

        $this->assertTrue($vinculo->fresh()->stock_pendiente, 'La Venta manual sí debe marcar pendiente.');
    }
}
