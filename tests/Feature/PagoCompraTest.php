<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Compras\Compras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagoCompraTest extends TestCase
{
    use RefreshDatabase;

    private function compraDe(float $total): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        // Precio sin IVA para que el total sea exacto.
        return app(Compras::class)->crear(
            ['proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => $total, 'iva_pct' => 0]],
        );
    }

    public function test_pago_parcial_y_total_transicionan_estado(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 400,
        ])->assertOk();

        $this->assertSame('parcial', $compra->fresh()->estado_pago);
        $this->assertEqualsWithDelta(600.0, app(Compras::class)->saldoPendiente($compra->fresh()), 0.01);

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 600,
        ])->assertOk();

        $this->assertSame('pagado', $compra->fresh()->estado_pago);
    }

    public function test_pago_genera_movimiento_que_baja_la_cuenta(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 500,
        ])->assertOk();

        $this->assertDatabaseHas('movimientos_tesoreria', [
            'tipo' => 'pago',
            'cuenta_origen_id' => $cuenta->id,
            'monto' => 500.00,
            'origen_type' => \App\Models\Pago::class,
        ]);
        $this->assertEqualsWithDelta(500.0, app(\App\Services\Tesoreria\Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
    }

    public function test_pago_mayor_al_saldo_es_rechazado(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 5000]);

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1500,
        ])->assertStatus(409);

        $this->assertDatabaseCount('pagos', 0);
        $this->assertSame('pendiente', $compra->fresh()->estado_pago);
    }

    public function test_cuenta_oculta_no_permitida(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->oculta()->create();

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_tesoreria_id');
    }
}
