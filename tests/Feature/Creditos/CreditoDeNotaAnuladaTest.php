<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sólo las Notas de Crédito **vigentes** aportan crédito disponible (FR-002). */
class CreditoDeNotaAnuladaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_nota_de_credito_anulada_no_aporta_credito(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 1000]);
        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 1000,
        ]);

        $this->assertEqualsWithDelta(1000.0, app(CreditoCliente::class)->disponible($venta->fresh()), 0.01);

        $nota->delete();

        // Anulada la nota, la venta vuelve a estar simplemente cobrada: no hay saldo a favor y por
        // lo tanto tampoco crédito. Si el cálculo mirara el monto nominal de la nota, seguiría
        // ofreciendo $1.000 que ya no existen.
        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($venta->fresh()));
        $this->assertEqualsWithDelta(0.0, $venta->fresh()->aCobrar(), 0.01);
    }
}
