<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las ventas migradas de Contagram (las que tienen `legacy_id`) se importan SIN descontar stock:
 * son historia y ese movimiento ya está consolidado en el saldo actual del producto.
 *
 * De ahí se desprende que borrarlas tampoco puede reintegrar stock — devolvería mercadería que
 * nunca salió. Este test fija esa regla para que no se rompa sin querer al tocar VentaObserver.
 *
 * Ver docs/importacion_2021_2026_plan_tecnico.md.
 */
class VentaMigradaStockTest extends TestCase
{
    use RefreshDatabase;

    private function armarVenta(?string $legacyId): array
    {
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $stock = Stock::create([
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => 10,
        ]);

        $venta = Venta::create([
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => '2024-05-10',
            'tipo_comprobante' => 'B',
            'subtotal_sin_descuento' => 100,
            'subtotal_con_descuento' => 100,
            'total' => 100,
            'legacy_id' => $legacyId,
        ]);

        VentaItem::create([
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => 3,
            'precio_unitario' => 100,
            'subtotal' => 300,
            'subtotal_con_iva' => 363,
        ]);

        return [$venta, $stock];
    }

    public function test_borrar_una_venta_migrada_no_reintegra_stock(): void
    {
        [$venta, $stock] = $this->armarVenta('2024-1234');

        $venta->delete();

        $this->assertEqualsWithDelta(10.0, (float) $stock->fresh()->cantidad, 0.001,
            'El stock de una venta migrada no debe moverse al borrarla: nunca se descontó.');
        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    public function test_borrar_una_venta_normal_si_reintegra_stock(): void
    {
        [$venta, $stock] = $this->armarVenta(null);

        $venta->delete();

        $this->assertEqualsWithDelta(13.0, (float) $stock->fresh()->cantidad, 0.001,
            'Una venta cargada en el CRM sí descontó stock, así que al borrarla se reintegra.');
    }
}
