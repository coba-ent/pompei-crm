<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Presupuesto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresupuestoConversionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spec 044 — "convertir a Venta" (crearVenta) redirige a ventas.create con los datos del Presupuesto
     * precargados en el formulario (VentaController@create, `presupuestoOrigen`); el submit final vuelve
     * a pasar por el mismo `CalculoComprobante` con los mismos ítems y `descuento_general_pct`. Se
     * verifica esa consistencia extremo a extremo: mismos ítems + mismo descuento general con múltiples
     * alícuotas deben dar el mismo total en Presupuesto y en la Venta resultante.
     */
    public function test_convertir_a_venta_conserva_el_total_con_descuento_general_y_multiples_alicuotas(): void
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $cliente = Cliente::factory()->create();
        $items = [
            ['descripcion' => 'Item 21%', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ['descripcion' => 'Item 10.5%', 'cantidad' => 1, 'precio_unitario' => 2000, 'iva_pct' => '10.5'],
        ];

        $this->postJson(route('presupuestos.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'descuento_general_pct' => 15,
            'items' => $items,
        ])->assertCreated();

        $presupuesto = Presupuesto::latest('id')->firstOrFail();
        $totalPresupuesto = $presupuesto->total;

        // "Crear Venta" desde el Presupuesto: no convierte del lado del servidor, redirige al form de
        // Ventas con el Presupuesto como origen (mismos ítems/descuento se re-envían en el submit).
        $this->postJson(route('presupuestos.crearVenta', $presupuesto))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'presupuesto_id' => $presupuesto->id,
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'tipo_comprobante' => 'B',
            'descuento_general_pct' => 15,
            'items' => $items,
        ])->assertCreated();

        $venta = Venta::latest('id')->firstOrFail();
        $this->assertSame($presupuesto->id, $venta->presupuesto_id);
        $this->assertEqualsWithDelta($totalPresupuesto, $venta->total, 0.01);
    }

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
