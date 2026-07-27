<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Ventas\Ventas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobroVentaTest extends TestCase
{
    use RefreshDatabase;

    private function ventaDe(float $total): Venta
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        // Precio sin IVA para que el total sea exacto.
        return app(Ventas::class)->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => $total, 'iva_pct' => 0]],
        );
    }

    public function test_cobro_parcial_y_total_transicionan_estado(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $this->postJson(route('ventas.cobros.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 400,
        ])->assertOk();

        $this->assertSame('parcial', $venta->fresh()->estado_cobro);
        $this->assertEqualsWithDelta(600.0, app(Ventas::class)->saldoPendiente($venta->fresh()), 0.01);

        $this->postJson(route('ventas.cobros.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 600,
        ])->assertOk();

        $this->assertSame('cobrado', $venta->fresh()->estado_cobro);
    }

    public function test_cobro_genera_movimiento_que_sube_la_cuenta(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $this->postJson(route('ventas.cobros.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 500,
        ])->assertOk();

        $this->assertDatabaseHas('movimientos_tesoreria', [
            'tipo' => 'cobro',
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 500.00,
            'origen_type' => \App\Models\Cobro::class,
        ]);
        $this->assertEqualsWithDelta(500.0, app(\App\Services\Tesoreria\Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
    }

    public function test_cobro_mayor_al_saldo_es_rechazado(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->create();

        $this->postJson(route('ventas.cobros.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1500,
        ])->assertStatus(409);

        $this->assertDatabaseCount('cobros', 0);
        $this->assertSame('pendiente', $venta->fresh()->estado_cobro);
    }

    public function test_cuenta_oculta_no_permitida(): void
    {
        $venta = $this->ventaDe(1000);
        $cuenta = CuentaTesoreria::factory()->oculta()->create();

        $this->postJson(route('ventas.cobros.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_tesoreria_id');
    }
}
