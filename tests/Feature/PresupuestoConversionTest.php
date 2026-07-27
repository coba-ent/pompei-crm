<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Presupuesto;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresupuestoConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_convertir_crea_una_venta_con_mismo_cliente_y_lineas_y_descuenta_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100]],
        ])->assertOk();

        $presupuesto = Presupuesto::firstOrFail();

        $response = $this->postJson(route('presupuestos.convertir', $presupuesto));
        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('ventas', 1);
        $venta = Venta::firstOrFail();
        $this->assertSame($cliente->id, $venta->cliente_id);
        $this->assertSame(1, $venta->items()->count());
        $this->assertSame($prod->id, $venta->items()->first()->producto_id);

        // Stock bajó vía la venta.
        $this->assertEqualsWithDelta(7.0, (float) Stock::first()->cantidad, 0.001);

        $presupuesto->refresh();
        $this->assertSame('convertido', $presupuesto->estado);
        $this->assertSame($venta->id, $presupuesto->venta_id);
        $this->assertSame($presupuesto->id, $venta->presupuesto_origen_id);
    }

    public function test_no_se_puede_convertir_dos_veces(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertOk();

        $presupuesto = Presupuesto::firstOrFail();

        $this->postJson(route('presupuestos.convertir', $presupuesto))->assertOk();
        $this->postJson(route('presupuestos.convertir', $presupuesto))->assertStatus(409);

        $this->assertDatabaseCount('ventas', 1);
    }

    public function test_ventas_sin_stock_off_y_stock_insuficiente_es_todo_o_nada(): void
    {
        config(['negocio.ventas_sin_stock' => false]);

        $cliente = Cliente::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 2]);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 5, 'precio' => 100]],
        ])->assertOk();

        $presupuesto = Presupuesto::firstOrFail();

        $this->postJson(route('presupuestos.convertir', $presupuesto))->assertStatus(409);

        $presupuesto->refresh();
        $this->assertNotSame('convertido', $presupuesto->estado);
        $this->assertNull($presupuesto->venta_id);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertEqualsWithDelta(2.0, (float) Stock::first()->cantidad, 0.001);
    }

    public function test_eliminar_la_venta_origen_limpia_el_enlace(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertOk();

        $presupuesto = Presupuesto::firstOrFail();
        $this->postJson(route('presupuestos.convertir', $presupuesto))->assertOk();

        $venta = Venta::firstOrFail();
        $this->deleteJson(route('ventas.destroy', $venta), ['confirmar' => 1])->assertOk();

        $presupuesto->refresh();
        $this->assertNull($presupuesto->venta_id);
        $this->assertNotSame('convertido', $presupuesto->estado);
        $this->assertTrue($presupuesto->es_convertible);
    }
}
