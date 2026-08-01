<?php

namespace Tests\Feature\Ingresos;

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
 * Regresión de spec 018 (T007): extender TiendanubeVarianteProducto/
 * TiendanubeConexionRest con las columnas nuevas de stock (T004/T005) no
 * debe cambiar qué depósito usa una Venta de Tiendanube, calcado del mismo
 * chequeo que ya existe para Mercado Libre en VentaStockTest.
 */
class VentaTiendanubeStockTest extends TestCase
{
    use RefreshDatabase;

    private function convertirOrdenTiendanube(Producto $producto): Venta
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        if (! auth()->user()->roles()->where('roles.id', $admin->id)->exists()) {
            auth()->user()->roles()->attach($admin->id);
        }

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
        ]);
        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);

        TiendanubeVarianteProducto::create(['variant_id' => 20, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create([
            'tn_order_id' => 5000001, 'status' => 'open', 'payment_status' => 'paid', 'fulfillment_status' => 'unpacked',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => 1210.00, 'moneda' => 'ARS', 'tn_customer_id' => 1, 'comprador_email' => 'comprador@test.com',
            'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => '10', 'variant_id' => 20, 'nombre_producto' => 'Producto',
            'cantidad' => 2, 'precio_unitario' => 605.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    public function test_venta_de_tiendanube_descuenta_stock_del_deposito_configurado(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $depositoTn = Deposito::create(['nombre' => 'Depósito TN', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        TiendanubeConexionRest::actual()->update(['deposito_id' => $depositoTn->id]);

        $this->convertirOrdenTiendanube($producto);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $depositoTn->id,
            'cantidad' => -2,
        ]);
    }

    public function test_venta_de_tiendanube_usa_deposito_por_defecto_si_no_hay_configurado(): void
    {
        $depositoDefault = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenTiendanube($producto);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $depositoDefault->id,
            'cantidad' => -2,
        ]);
    }
}
