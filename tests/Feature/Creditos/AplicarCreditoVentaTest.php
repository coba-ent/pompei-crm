<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoints de aplicación de saldo a favor en Ventas (US1, contracts §1 y §2). */
class AplicarCreditoVentaTest extends TestCase
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

    /** @return array{0: Venta, 1: Venta} origen con crédito, destino con saldo pendiente */
    private function escenario(float $credito = 30771.29, float $destino = 27306.00): array
    {
        $cliente = Cliente::factory()->create();

        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => $credito, 'fecha_emision' => '2026-08-19',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => $credito]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => $credito]);

        return [$origen, Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => $destino, 'fecha_emision' => '2026-08-20',
        ])];
    }

    public function test_el_endpoint_de_disponible_devuelve_el_aplicable_y_los_origenes(): void
    {
        [$origen, $destino] = $this->escenario();

        $this->getJson(route('ventas.credito.disponible', $destino))
            ->assertOk()
            ->assertJsonPath('disponible_total', 30771.29)
            ->assertJsonPath('saldo_pendiente', 27306)
            ->assertJsonPath('aplicable', 27306)
            ->assertJsonPath('origenes.0.comprobante_id', $origen->id);
    }

    public function test_aplicacion_exitosa_deja_la_venta_cobrada_y_el_remanente_disponible(): void
    {
        [$origen, $destino] = $this->escenario();

        $this->postJson(route('ventas.aplicaciones-credito.store', $destino), [
            'monto' => 27306.00, 'fecha' => '2026-08-20',
        ])
            ->assertCreated()
            ->assertJsonPath('a_cobrar', 0)
            ->assertJsonPath('estado_cobro', 'cobrada')
            ->assertJsonPath('credito_disponible_restante', 3465.29)
            ->assertJsonPath('aplicaciones.0.origen_id', $origen->id);

        $this->assertDatabaseHas('aplicaciones_credito', [
            'origen_id' => $origen->id,
            'destino_id' => $destino->id,
            'monto' => 27306.00,
        ]);
    }

    public function test_no_se_puede_aplicar_mas_que_el_saldo_del_comprobante(): void
    {
        [, $destino] = $this->escenario();

        $this->postJson(route('ventas.aplicaciones-credito.store', $destino), [
            'monto' => 30000.00, 'fecha' => '2026-08-20',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertDatabaseCount('aplicaciones_credito', 0);
    }

    public function test_no_se_puede_aplicar_mas_que_el_credito_disponible(): void
    {
        // Crédito de $500 contra una venta de $2.000: el tope lo pone el crédito, no el saldo.
        [, $destino] = $this->escenario(credito: 500.00, destino: 2000.00);

        $this->postJson(route('ventas.aplicaciones-credito.store', $destino), [
            'monto' => 800.00, 'fecha' => '2026-08-20',
        ])->assertStatus(422);

        $this->postJson(route('ventas.aplicaciones-credito.store', $destino), [
            'monto' => 500.00, 'fecha' => '2026-08-20',
        ])->assertCreated()->assertJsonPath('a_cobrar', 1500);
    }

    public function test_no_se_puede_aplicar_el_credito_de_un_comprobante_sobre_si_mismo(): void
    {
        [$origen] = $this->escenario();

        // El origen tiene saldo a favor, no saldo pendiente: no hay nada que aplicarle, y menos
        // su propio crédito (FR-009a).
        $this->postJson(route('ventas.aplicaciones-credito.store', $origen), [
            'monto' => 100.00, 'fecha' => '2026-08-20', 'origen_id' => $origen->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('aplicaciones_credito', 0);
    }

    public function test_no_se_ofrece_ni_se_aplica_el_credito_de_otro_cliente(): void
    {
        [$origen] = $this->escenario();

        $otroCliente = Cliente::factory()->create();
        $destinoAjeno = Venta::factory()->create([
            'cliente_id' => $otroCliente->id, 'total' => 1000, 'fecha_emision' => '2026-08-20',
        ]);

        $this->getJson(route('ventas.credito.disponible', $destinoAjeno))
            ->assertOk()
            ->assertJsonPath('disponible_total', 0)
            ->assertJsonPath('origenes', []);

        $this->postJson(route('ventas.aplicaciones-credito.store', $destinoAjeno), [
            'monto' => 100.00, 'fecha' => '2026-08-20', 'origen_id' => $origen->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('aplicaciones_credito', 0);
    }

    public function test_anular_la_aplicacion_devuelve_el_saldo_al_comprobante(): void
    {
        [, $destino] = $this->escenario();

        $resp = $this->postJson(route('ventas.aplicaciones-credito.store', $destino), [
            'monto' => 27306.00, 'fecha' => '2026-08-20',
        ])->assertCreated();

        $id = $resp->json('aplicaciones.0.id');

        $this->deleteJson(route('ventas.aplicaciones-credito.destroy', [$destino, $id]))
            ->assertOk()
            ->assertJsonPath('a_cobrar', 27306)
            ->assertJsonPath('estado_cobro', 'sin_cobrar');

        $this->assertSoftDeleted('aplicaciones_credito', ['id' => $id]);
    }
}
