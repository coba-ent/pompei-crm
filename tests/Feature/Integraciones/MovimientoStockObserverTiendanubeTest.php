<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rama Tiendanube de `MovimientoStockObserver`, espejo de la de Mercado Libre.
 *
 * Tiendanube descuenta el stock **sólo de la variante vendida**. Si el producto está vinculado
 * a más de una variante, las demás siguen ofreciendo el stock viejo: saltear el producto entero
 * —como se hacía antes— las dejaba desfasadas para siempre, porque nadie las volvía a marcar.
 */
class MovimientoStockObserverTiendanubeTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANTE_VENDIDA = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        if (! auth()->user()->roles()->where('roles.id', $admin->id)->exists()) {
            auth()->user()->roles()->attach($admin->id);
        }

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);

        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);
    }

    private function convertirOrdenTiendanube(Producto $producto): Venta
    {
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 5000001, 'status' => 'open', 'payment_status' => 'paid',
            'fulfillment_status' => 'unpacked', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => 1, 'comprador_email' => 'comprador@test.com', 'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => '10', 'variant_id' => self::VARIANTE_VENDIDA,
            'nombre_producto' => 'Producto', 'cantidad' => 2, 'precio_unitario' => 605.00,
            'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    private function productoVinculado(): Producto
    {
        Deposito::firstOrCreate(['nombre' => 'Principal'], ['activo' => true]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        TiendanubeVarianteProducto::create([
            'variant_id' => self::VARIANTE_VENDIDA, 'tn_product_id' => '10', 'producto_id' => $producto->id,
        ]);

        return $producto;
    }

    public function test_orden_de_tiendanube_no_marca_pendiente_la_variante_vendida(): void
    {
        $producto = $this->productoVinculado();

        $this->convertirOrdenTiendanube($producto);

        $vendida = TiendanubeVarianteProducto::where('variant_id', self::VARIANTE_VENDIDA)->firstOrFail();
        $this->assertFalse($vendida->fresh()->stock_pendiente, 'Tiendanube ya descontó esa variante.');
    }

    public function test_orden_de_tiendanube_marca_pendientes_las_otras_variantes_del_producto(): void
    {
        $producto = $this->productoVinculado();

        $otra = TiendanubeVarianteProducto::create([
            'variant_id' => 99, 'tn_product_id' => '11', 'producto_id' => $producto->id,
        ]);

        $this->convertirOrdenTiendanube($producto);

        $vendida = TiendanubeVarianteProducto::where('variant_id', self::VARIANTE_VENDIDA)->firstOrFail();
        $this->assertFalse($vendida->fresh()->stock_pendiente, 'Tiendanube ya descontó la variante vendida.');
        $this->assertTrue($otra->fresh()->stock_pendiente, 'La otra variante quedó con el stock viejo.');
    }
}
