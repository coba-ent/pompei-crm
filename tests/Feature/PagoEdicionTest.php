<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Compras\Compras;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagoEdicionTest extends TestCase
{
    use RefreshDatabase;

    private function compraDe(float $total): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        return app(Compras::class)->crear(
            ['proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => $total, 'iva_pct' => 0]],
        );
    }

    public function test_editar_pago_reversa_y_recrea_movimiento(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $pago = app(Compras::class)->agregarPago($compra, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 400,
        ]);

        $this->putJson(route('compras.pagos.update', [$compra, $pago]), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ])->assertOk();

        // El saldo de la cuenta refleja sólo el pago vigente (resta 1000), no 1400.
        $this->assertEqualsWithDelta(0.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
        $this->assertSame('pagado', $compra->fresh()->estado_pago);
        // Hay un contramovimiento (reversa) del original.
        $this->assertTrue(\App\Models\MovimientoTesoreria::whereNotNull('revierte_a_id')->exists());
    }

    public function test_eliminar_pago_reversa_y_recalcula_estado(): void
    {
        $compra = $this->compraDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $pago = app(Compras::class)->agregarPago($compra, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ]);
        $this->assertSame('pagado', $compra->fresh()->estado_pago);

        $this->deleteJson(route('compras.pagos.destroy', [$compra, $pago]))->assertOk();

        $this->assertSame('pendiente', $compra->fresh()->estado_pago);
        $this->assertEqualsWithDelta(1000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
        $this->assertDatabaseCount('pagos', 0);
    }
}
