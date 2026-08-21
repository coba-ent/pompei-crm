<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Con varios comprobantes con saldo a favor, el consumo va del más antiguo al más nuevo (FR-008) y
 * un mismo importe puede cubrirse con más de un origen, generando una fila por origen (contrato §2).
 */
class OrdenDeConsumoTest extends TestCase
{
    use RefreshDatabase;

    private function origenConCredito(Cliente $cliente, float $monto, string $fecha): Venta
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => $monto, 'fecha_emision' => $fecha,
        ]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => $monto]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => $monto]);

        return $venta;
    }

    public function test_consume_del_mas_antiguo_al_mas_nuevo_y_puede_usar_varios_origenes(): void
    {
        $cliente = Cliente::factory()->create();

        $viejo = $this->origenConCredito($cliente, 300.00, '2026-06-01');
        $nuevo = $this->origenConCredito($cliente, 500.00, '2026-07-01');

        $destino = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 700.00, 'fecha_emision' => '2026-08-20',
        ]);

        $aplicaciones = app(CreditoCliente::class)->aplicar($destino, 700.00, now());

        $this->assertCount(2, $aplicaciones);
        $this->assertSame($viejo->id, $aplicaciones[0]->origen_id, 'Debe consumir primero el más antiguo.');
        $this->assertEqualsWithDelta(300.00, (float) $aplicaciones[0]->monto, 0.01);
        $this->assertSame($nuevo->id, $aplicaciones[1]->origen_id);
        $this->assertEqualsWithDelta(400.00, (float) $aplicaciones[1]->monto, 0.01);

        $this->assertEqualsWithDelta(0.0, $destino->fresh()->aCobrar(), 0.01);
        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($viejo->fresh()));
        $this->assertEqualsWithDelta(100.00, app(CreditoCliente::class)->disponible($nuevo->fresh()), 0.01);
    }

    public function test_una_misma_nota_puede_aplicarse_a_varios_comprobantes_hasta_agotarse(): void
    {
        $cliente = Cliente::factory()->create();
        $origen = $this->origenConCredito($cliente, 1000.00, '2026-06-01');

        foreach ([400.00, 600.00] as $importe) {
            $destino = Venta::factory()->create([
                'cliente_id' => $cliente->id, 'total' => $importe, 'fecha_emision' => '2026-08-20',
            ]);
            app(CreditoCliente::class)->aplicar($destino, $importe, now());
            $this->assertEqualsWithDelta(0.0, $destino->fresh()->aCobrar(), 0.01);
        }

        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($origen->fresh()));
    }
}
