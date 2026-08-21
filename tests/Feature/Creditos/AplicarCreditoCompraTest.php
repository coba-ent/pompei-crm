<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saldo a favor de proveedor aplicado a una Compra (US4). */
class AplicarCreditoCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);
    }

    /** @return array{0: Compra, 1: Compra} */
    private function escenario(float $credito = 5000.00, float $destino = 3000.00): array
    {
        $proveedor = Proveedor::factory()->create();

        $origen = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => $credito, 'fecha_emision' => '2026-08-10',
        ]);
        Pago::factory()->create(['compra_id' => $origen->id, 'monto' => $credito]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $origen->id, 'tipo' => 'credito', 'monto' => $credito,
        ]);

        return [$origen, Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => $destino, 'fecha_emision' => '2026-08-20',
        ])];
    }

    public function test_aplicacion_exitosa_deja_la_compra_pagada_y_el_remanente_disponible(): void
    {
        [$origen, $destino] = $this->escenario();

        $this->postJson(route('compras.aplicaciones-credito.store', $destino), [
            'monto' => 3000.00, 'fecha' => '2026-08-20',
        ])
            ->assertCreated()
            ->assertJsonPath('a_pagar', 0)
            ->assertJsonPath('estado_pago', 'pagado')
            ->assertJsonPath('credito_disponible_restante', 2000)
            ->assertJsonPath('aplicaciones.0.origen_id', $origen->id);

        $this->assertEqualsWithDelta(-2000.00, $origen->fresh()->aPagar(), 0.01);
    }

    public function test_no_se_puede_aplicar_mas_que_el_saldo_ni_mas_que_el_credito(): void
    {
        [, $destino] = $this->escenario(credito: 1000.00, destino: 3000.00);

        // Tope por crédito disponible.
        $this->postJson(route('compras.aplicaciones-credito.store', $destino), [
            'monto' => 1500.00, 'fecha' => '2026-08-20',
        ])->assertStatus(422);

        [, $destinoChico] = $this->escenario(credito: 5000.00, destino: 800.00);

        // Tope por saldo del comprobante.
        $this->postJson(route('compras.aplicaciones-credito.store', $destinoChico), [
            'monto' => 1200.00, 'fecha' => '2026-08-20',
        ])->assertStatus(422);
    }

    /** Clientes y proveedores son universos separados: no se compensan (Assumptions de la spec). */
    public function test_un_credito_de_cliente_no_aparece_disponible_en_compras(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 9999.00]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 9999.00]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 9999.00]);

        $compra = Compra::factory()->create(['total' => 1000.00, 'fecha_emision' => '2026-08-20']);

        $this->getJson(route('compras.credito.disponible', $compra))
            ->assertOk()
            ->assertJsonPath('disponible_total', 0)
            ->assertJsonPath('origenes', []);
    }
}
