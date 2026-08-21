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
 * Crédito disponible = saldo a favor **efectivo**, nunca el monto nominal de la Nota de Crédito
 * (spec 072, FR-001 y research Decisión 2).
 */
class CalculoCreditoDisponibleTest extends TestCase
{
    use RefreshDatabase;

    private function venta(Cliente $cliente, float $total, string $fecha = '2026-08-20'): Venta
    {
        return Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => $total,
            'fecha_emision' => $fecha,
        ]);
    }

    public function test_venta_pagada_y_anulada_por_nc_deja_credito_por_el_saldo_a_favor(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = $this->venta($cliente, 30771.29);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 30771.29]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30771.29]);

        $this->assertEqualsWithDelta(30771.29, app(CreditoCliente::class)->disponible($venta->fresh()), 0.01);
    }

    /**
     * El caso que motivó la clarificación: la venta 24582 tal como quedó en la base hoy —NC de
     * $30.771,29 y cero cobros porque el cobro se borró a mano— NO tiene crédito. La NC sólo
     * canceló deuda; tomar el monto de la nota crearía crédito de la nada.
     */
    public function test_venta_impaga_con_nc_no_genera_credito(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = $this->venta($cliente, 30771.29);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30771.29]);

        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($venta->fresh()));
    }

    public function test_venta_sobrecobrada_sin_nc_no_ofrece_credito(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = $this->venta($cliente, 1000);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 1500]);

        // Sigue siendo saldo a favor en la cuenta corriente, pero no es crédito aplicable:
        // el crédito nace sólo de Notas de Crédito (Assumptions de la spec).
        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($venta->fresh()));
        $this->assertEqualsWithDelta(-500.0, $venta->fresh()->aCobrar(), 0.01);
    }

    public function test_el_credito_ya_cedido_se_descuenta_del_disponible(): void
    {
        $cliente = Cliente::factory()->create();
        $origen = $this->venta($cliente, 30771.29, '2026-08-19');
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 30771.29]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 30771.29]);

        $destino = $this->venta($cliente, 27306.00, '2026-08-20');

        app(CreditoCliente::class)->aplicar($destino, 27306.00, now());

        $this->assertEqualsWithDelta(3465.29, app(CreditoCliente::class)->disponible($origen->fresh()), 0.01);
    }
}
