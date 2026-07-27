<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Tesoreria\Tesoreria;
use App\Services\Ventas\Ventas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobroEdicionTest extends TestCase
{
    use RefreshDatabase;

    private function ventaDe(float $total): Venta
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        return app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => $total, 'iva_pct' => 0]],
        );
    }

    public function test_editar_cobro_reversa_y_recrea_movimiento(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $cobro = app(Ventas::class)->agregarCobro($venta, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 400,
        ]);

        $this->putJson(route('ventas.cobros.update', [$venta, $cobro]), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ])->assertOk();

        // El saldo de la cuenta refleja sólo el cobro vigente (1000), no 1400.
        $this->assertEqualsWithDelta(1000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
        $this->assertSame('cobrado', $venta->fresh()->estado_cobro);
        // Hay un contramovimiento (reversa) del original.
        $this->assertTrue(\App\Models\MovimientoTesoreria::whereNotNull('revierte_a_id')->exists());
    }

    public function test_eliminar_cobro_reversa_y_recalcula_estado(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $cobro = app(Ventas::class)->agregarCobro($venta, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ]);
        $this->assertSame('cobrado', $venta->fresh()->estado_cobro);

        $this->deleteJson(route('ventas.cobros.destroy', [$venta, $cobro]))->assertOk();

        $this->assertSame('pendiente', $venta->fresh()->estado_cobro);
        $this->assertEqualsWithDelta(0.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
        $this->assertDatabaseCount('cobros', 0);
    }
}
