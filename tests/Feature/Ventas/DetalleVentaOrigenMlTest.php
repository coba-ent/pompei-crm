<?php

namespace Tests\Feature\Ventas;

use App\Models\Cliente;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detalle de Venta: ID en el encabezado, depósito que descontó el stock, y la cascada de precios de
 * Mercado Libre que explica por qué el total no coincide con el precio publicado (14/08/2026).
 */
class DetalleVentaOrigenMlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function ventaDeMl(): Venta
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => 'B',
            'total' => 126361.89,
            'origen' => 'mercadolibre',
        ]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '2000017931860790', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => 126361.89, 'moneda' => 'ARS', 'comprador_ml_id' => '204859164',
            'comprador_apodo' => 'CECILIAAGUILANTE', 'venta_id' => $venta->id, 'sincronizada_en' => now(),
            'payload' => ['payments' => [['coupon_amount' => 5458.83, 'total_paid_amount' => 151648.71, 'installments' => 3]]],
        ]);

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1387774241', 'titulo' => 'Espejo Peinador',
            'cantidad' => 1, 'precio_unitario' => 126361.89, 'precio_bruto' => 131416.37,
            'comision_ml' => 18069.75, 'total_linea' => 126361.89,
        ]);

        return $venta->fresh();
    }

    public function test_muestra_el_id_de_la_venta_en_el_encabezado(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory()->create()->id]);

        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertSee("Venta #{$venta->id}");
    }

    public function test_muestra_la_cascada_de_precios_de_mercado_libre(): void
    {
        $respuesta = $this->get(route('ventas.show', $this->ventaDeMl()))->assertOk();

        $respuesta->assertSee('Precios de Mercado Libre');
        $respuesta->assertSee('131.416,37');   // precio de lista
        $respuesta->assertSee('5.054,48');     // aporte del vendedor
        $respuesta->assertSee('126.361,89');   // precio registrado en la Venta
        $respuesta->assertSee('5.458,83');     // cupón de ML
        $respuesta->assertSee('120.903,06');   // lo que pagó el comprador
        $respuesta->assertSee('18.069,75');    // comisión
        $respuesta->assertSee('108.292,14');   // neto antes de envío
    }

    public function test_venta_sin_origen_ml_no_muestra_el_bloque(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory()->create()->id]);

        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertDontSee('Precios de Mercado Libre');
    }

    /** Sin diferencia entre precio de lista y precio de venta no hay nada que explicar. */
    public function test_venta_de_ml_sin_descuento_no_muestra_el_bloque(): void
    {
        $venta = $this->ventaDeMl();
        $venta->mlOrden->items()->update(['precio_bruto' => 126361.89]);
        $venta->mlOrden->update(['payload' => ['payments' => [['coupon_amount' => 0]]]]);

        $this->get(route('ventas.show', $venta->fresh()))
            ->assertOk()
            ->assertDontSee('Precios de Mercado Libre');
    }

    public function test_muestra_el_deposito_que_descontó_el_stock(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory()->create()->id]);
        $deposito = \App\Models\Deposito::create(['nombre' => 'Depósito Full', 'activo' => true]);
        $producto = \App\Models\Producto::factory()->create();

        $venta->movimientosStock()->create([
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'tipo' => 'salida',
            'cantidad' => 1,
            'fecha' => now(),
        ]);

        $this->get(route('ventas.show', $venta->fresh()))
            ->assertOk()
            ->assertSee('Depósito Full');
    }
}
