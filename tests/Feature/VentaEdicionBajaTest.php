<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\Venta;
use App\Services\Tesoreria\Tesoreria;
use App\Services\Ventas\Ventas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaEdicionBajaTest extends TestCase
{
    use RefreshDatabase;

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_editar_reajusta_stock_y_recalcula_total(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $venta = app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18', 'deposito_id' => $deposito->id],
            [['producto_id' => $prod->id, 'cantidad' => 4, 'precio' => 100, 'iva_pct' => 0]],
        );
        $this->assertEqualsWithDelta(6.0, (float) Stock::first()->cantidad, 0.001);

        // Editar a cantidad 1 → reintegra 4 y descuenta 1 → stock 9.
        $this->putJson(route('ventas.update', $venta), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100, 'iva_pct' => 0]],
        ])->assertOk();

        $this->assertEqualsWithDelta(9.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertEquals(100.00, (float) $venta->fresh()->total);
    }

    public function test_eliminar_sin_cobros_reintegra_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $venta = app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18', 'deposito_id' => $deposito->id],
            [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100, 'iva_pct' => 0]],
        );
        $this->assertEqualsWithDelta(7.0, (float) Stock::first()->cantidad, 0.001);

        $this->deleteJson(route('ventas.destroy', $venta))->assertOk();

        $this->assertEqualsWithDelta(10.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
    }

    public function test_eliminar_con_cobros_requiere_confirmacion_y_revierte(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $venta = app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => 0]],
        );
        app(Ventas::class)->agregarCobro($venta, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ]);

        // Sin confirmar → 409.
        $this->deleteJson(route('ventas.destroy', $venta))->assertStatus(409);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);

        // Con confirmación → revierte cobros y elimina.
        $this->deleteJson(route('ventas.destroy', $venta), ['confirmar' => 1])->assertOk();
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
        $this->assertEqualsWithDelta(0.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
    }
}
