<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Stock;
use App\Services\Ventas\Ventas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemitoTest extends TestCase
{
    use RefreshDatabase;

    public function test_generar_remito_no_altera_stock_y_crea_registro(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $venta = app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18', 'deposito_id' => $deposito->id],
            [['producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100, 'iva_pct' => 0]],
        );
        $stockAntes = (float) Stock::first()->cantidad;

        $this->postJson(route('ventas.remito.store', $venta))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('remitos', ['venta_id' => $venta->id]);
        $this->assertEqualsWithDelta($stockAntes, (float) Stock::first()->cantidad, 0.001);

        // La vista imprimible no contiene CAE ni datos fiscales.
        $html = $this->get(route('ventas.remito.show', $venta))->assertOk()->getContent();
        $this->assertStringContainsString('REMITO', $html);
        $this->assertStringNotContainsString('CAE', $html);
    }
}
