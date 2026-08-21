<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Ingresos\Cobranzas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión: esta feature **agrega** un camino, no modifica el de siempre (FR-020, FR-021).
 *
 * Una cobranza con dinero sigue generando su movimiento de tesorería, y una Nota de Crédito por un
 * monto mayor al del comprobante sigue siendo posible — que es justamente el mecanismo por el cual
 * nace el saldo a favor.
 */
class CircuitoDineroIntactoTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_cobranza_con_dinero_sigue_generando_su_movimiento_de_tesoreria(): void
    {
        $venta = Venta::factory()->create(['total' => 1000]);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $cobro = app(Cobranzas::class)->registrarCobro($venta, 1000.0, $cuenta, now());

        $this->assertDatabaseHas('movimientos_tesoreria', [
            'tipo' => 'cobro',
            'origen_type' => \App\Models\Cobro::class,
            'origen_id' => $cobro->id,
        ]);
        $this->assertSame(1, MovimientoTesoreria::count());
        $this->assertEqualsWithDelta(0.0, $venta->fresh()->aCobrar(), 0.01);
        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());
    }

    public function test_sigue_permitida_una_nota_de_credito_mayor_al_comprobante(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 1500,
        ]);

        $this->assertEqualsWithDelta(-500.0, $venta->fresh()->aCobrar(), 0.01);
    }
}
