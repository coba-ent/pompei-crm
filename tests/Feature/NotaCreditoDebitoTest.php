<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US4 — NC/ND: afecta stock vía StockService, y ajusta la barra de ecuación de la venta (A Cobrar). */
class NotaCreditoDebitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_nota_de_credito_que_afecta_stock_genera_movimiento_de_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $stockAntes = $producto->stockTotal();

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100],
            ],
            'fecha_emision' => now()->toDateString(),
            'monto' => 300,
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame($stockAntes + 3.0, $producto->fresh()->stockTotal());
        $this->assertDatabaseHas('movimientos_stock', ['producto_id' => $producto->id, 'cantidad' => 3]);
    }

    public function test_nc_resta_y_nd_suma_en_la_barra_de_ecuacion_de_la_venta(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->assertSame(1000.0, $venta->aCobrar());

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Devolución parcial',
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
        ])->assertCreated();

        $this->assertSame(800.0, $venta->fresh()->aCobrar());

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'debito',
            'afecta_stock' => false,
            'descripcion' => 'Interés por mora',
            'fecha_emision' => now()->toDateString(),
            'monto' => 50,
        ])->assertCreated();

        $this->assertSame(850.0, $venta->fresh()->aCobrar());
    }
}
