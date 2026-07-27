<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraStockTest extends TestCase
{
    use RefreshDatabase;

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_linea_producto_aumenta_stock(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100]],
        ])->assertOk();

        $this->assertEqualsWithDelta(13.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $prod->id,
            'tipo' => 'entrada',
            'origen_type' => \App\Models\CompraItem::class,
        ]);
    }

    public function test_linea_servicio_no_aumenta_stock(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 5, 'precio' => 100]],
        ])->assertOk();

        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    public function test_compra_nunca_se_bloquea_por_stock(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        // Sin fila de stock previa: una venta rechazaría esto, una compra no.

        $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1000, 'precio' => 100]],
        ])->assertOk();

        $this->assertEqualsWithDelta(1000.0, (float) Stock::first()->cantidad, 0.001);
    }
}
