<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anular una aplicación devuelve el crédito al origen (FR-011) y una Nota de Crédito con crédito
 * aplicado no se puede eliminar hasta revertir esas aplicaciones (FR-012).
 */
class AnulacionCreditoTest extends TestCase
{
    use RefreshDatabase;

    private Venta $origen;

    private Venta $destino;

    private NotaCreditoDebito $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        $cliente = Cliente::factory()->create();

        $this->origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 1000, 'fecha_emision' => '2026-08-01',
        ]);
        Cobro::factory()->create(['venta_id' => $this->origen->id, 'monto' => 1000]);
        $this->nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $this->origen->id, 'tipo' => 'credito', 'monto' => 1000,
        ]);

        $this->destino = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 400, 'fecha_emision' => '2026-08-20',
        ]);
    }

    public function test_anular_una_aplicacion_devuelve_el_credito_al_origen(): void
    {
        $aplicacion = app(CreditoCliente::class)->aplicar($this->destino, 400.0, now())->first();

        $this->assertEqualsWithDelta(600.0, app(CreditoCliente::class)->disponible($this->origen->fresh()), 0.01);

        $this->deleteJson(route('ventas.aplicaciones-credito.destroy', [$this->destino, $aplicacion]))
            ->assertOk();

        $this->assertEqualsWithDelta(1000.0, app(CreditoCliente::class)->disponible($this->origen->fresh()), 0.01);
        $this->assertEqualsWithDelta(400.0, $this->destino->fresh()->aCobrar(), 0.01);
    }

    public function test_no_se_puede_eliminar_una_nota_con_credito_aplicado(): void
    {
        app(CreditoCliente::class)->aplicar($this->destino, 400.0, now());

        $this->deleteJson(route('ventas.notas.destroy', [$this->origen, $this->nota]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('notas_credito_debito', ['id' => $this->nota->id, 'deleted_at' => null]);
    }

    public function test_revertidas_las_aplicaciones_la_nota_vuelve_a_ser_eliminable(): void
    {
        $aplicacion = app(CreditoCliente::class)->aplicar($this->destino, 400.0, now())->first();

        $this->deleteJson(route('ventas.aplicaciones-credito.destroy', [$this->destino, $aplicacion]))->assertOk();

        $this->deleteJson(route('ventas.notas.destroy', [$this->origen, $this->nota]))->assertOk();

        $this->assertSoftDeleted('notas_credito_debito', ['id' => $this->nota->id]);
    }
}
