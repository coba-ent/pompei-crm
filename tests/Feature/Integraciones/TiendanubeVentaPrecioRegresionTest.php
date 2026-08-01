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
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión crítica (spec 018 ampliación, FR-039/FR-040): con la Lista de
 * Precios de Tiendanube configurada y precio cargado ahí para el producto de
 * la orden, la conversión de una orden de Tiendanube en Venta sigue
 * derivando el total/precio de línea 100% del importe pagado en la orden, no
 * de la Lista de Precios — y la Venta no queda con lista_precio_id asignado.
 */
class TiendanubeVentaPrecioRegresionTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_ignora_la_lista_de_precios_configurada_para_el_calculo(): void
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
            'lista_precio_id' => $lista->id,
        ]);
        $cuenta = CuentaTesoreria::firstOrCreate(['nombre' => 'Tiendanube'], ['tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuenta->id]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        // Precio "de lista" deliberadamente distinto del pagado en la orden.
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 999999.00]);

        TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $totalPagado = 605.00;
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 7000001, 'status' => 'open', 'payment_status' => 'paid', 'fulfillment_status' => 'unpacked',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => $totalPagado, 'moneda' => 'ARS', 'tn_customer_id' => 1, 'comprador_email' => 'comprador@test.com',
            'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => '10', 'variant_id' => 1, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => $totalPagado, 'total_linea' => $totalPagado, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $venta = $resultado['venta'];
        $this->assertEqualsWithDelta($totalPagado, (float) $venta->total, 0.01);
        $this->assertNull($venta->lista_precio_id);
    }
}
