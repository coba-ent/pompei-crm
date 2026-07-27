<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaStockTest extends TestCase
{
    use RefreshDatabase;

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_linea_producto_descuenta_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100]],
        ])->assertOk();

        $this->assertEqualsWithDelta(7.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $prod->id,
            'tipo' => 'salida',
            'origen_type' => \App\Models\VentaItem::class,
        ]);
    }

    public function test_linea_servicio_no_descuenta_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 5, 'precio' => 100]],
        ])->assertOk();

        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    public function test_ventas_sin_stock_off_rechaza_sobreventa(): void
    {
        config(['negocio.ventas_sin_stock' => false]);

        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 2]);

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 5, 'precio' => 100]],
        ])->assertStatus(409);

        // La transacción no dejó rastro.
        $this->assertDatabaseCount('ventas', 0);
        $this->assertEqualsWithDelta(2.0, (float) Stock::first()->cantidad, 0.001);
    }

    public function test_ventas_sin_stock_on_permite_negativo(): void
    {
        config(['negocio.ventas_sin_stock' => true]);

        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 2]);

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 5, 'precio' => 100]],
        ])->assertOk();

        $this->assertEqualsWithDelta(-3.0, (float) Stock::first()->cantidad, 0.001);
    }
}
