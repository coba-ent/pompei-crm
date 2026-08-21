<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aplicar crédito es una **transferencia de saldo** entre dos comprobantes del mismo cliente
 * (FR-003a, SC-001a): no crea ni destruye deuda, sólo la reubica.
 *
 * Reproduce el caso FLORENCIA 1159751732 con los importes reales del 20/08/2026 (ventas 24582 y
 * 24608): sin el término `creditoCedido()` el saldo a favor quedaría entero en el origen Y además
 * saldaría el destino, y el cliente terminaría con $30.771,29 a favor en vez de $3.465,29.
 */
class TransferenciaDeSaldoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_saldo_del_cliente_es_identico_antes_y_despues_de_aplicar(): void
    {
        $cliente = Cliente::factory()->create();

        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 30771.29, 'fecha_emision' => '2026-08-19',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 30771.29]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 30771.29]);

        $destino = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 27306.00, 'fecha_emision' => '2026-08-20',
        ]);

        $saldoAntes = $this->saldoDelCliente($cliente->id);
        $this->assertEqualsWithDelta(-3465.29, $saldoAntes, 0.01);

        app(CreditoCliente::class)->aplicar($destino, 27306.00, now());

        // El destino queda saldado y el origen conserva sólo el remanente.
        $this->assertEqualsWithDelta(0.0, $destino->fresh()->aCobrar(), 0.01);
        $this->assertEqualsWithDelta(-3465.29, $origen->fresh()->aCobrar(), 0.01);
        $this->assertSame('cobrada', $destino->fresh()->estadoCobro());

        // Y el saldo del cliente no se movió: es el corazón de la feature.
        $this->assertEqualsWithDelta($saldoAntes, $this->saldoDelCliente($cliente->id), 0.01);
        $this->assertEqualsWithDelta(-3465.29, $this->saldoDelCliente($cliente->id), 0.01);
    }

    public function test_anular_la_aplicacion_devuelve_el_credito_y_el_saldo_sigue_igual(): void
    {
        $cliente = Cliente::factory()->create();

        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 1000, 'fecha_emision' => '2026-08-19',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 1000]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 1000]);

        $destino = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 400, 'fecha_emision' => '2026-08-20',
        ]);

        $saldoAntes = $this->saldoDelCliente($cliente->id);

        $aplicaciones = app(CreditoCliente::class)->aplicar($destino, 400.0, now());
        app(CreditoCliente::class)->anular($aplicaciones->first());

        $this->assertEqualsWithDelta(1000.0, app(CreditoCliente::class)->disponible($origen->fresh()), 0.01);
        $this->assertEqualsWithDelta(400.0, $destino->fresh()->aCobrar(), 0.01);
        $this->assertEqualsWithDelta($saldoAntes, $this->saldoDelCliente($cliente->id), 0.01);
    }

    /** Saldo de cuenta corriente del cliente por el mismo camino que usa la pantalla. */
    private function saldoDelCliente(int $clienteId): float
    {
        $fila = app(CuentaCorriente::class)->porCliente('cliente')
            ->firstWhere('cliente_id', $clienteId);

        return $fila === null ? 0.0 : (float) $fila['total'];
    }
}
