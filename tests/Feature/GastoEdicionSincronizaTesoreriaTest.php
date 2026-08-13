<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar un Gasto ya conciliado tiene que mover su movimiento de tesorería con él.
 *
 * `Pagos::conciliarGasto()` salía sin hacer nada cuando el gasto ya tenía movimiento, así que
 * cambiarle la cuenta lo dejaba descontando de la anterior, y cambiarle el monto o la fecha no se
 * reflejaba en ningún saldo. En producción quedó un caso: el gasto "Ley 25413" ($660,80 del
 * 10/08/2026) descontaba de `Caja del Local` con el gasto ya pasado a `Banco Credicoop`.
 *
 * Había un test que cubría esto (`GastoEdicionBajaTest`) pero apunta a `App\Services\Gastos\Gastos`,
 * que no existe más — falla al construirse y nunca llegó a correr. Por eso el bug pasó inadvertido.
 */
class GastoEdicionSincronizaTesoreriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Rol::create(['nombre' => 'Admin', 'es_sistema' => true])->id);

        return $user;
    }

    private function gastoPagado(CuentaTesoreria $cuenta, Categoria $categoria): Gasto
    {
        $gasto = Gasto::create([
            'fecha' => '2026-07-18',
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ]);

        app(\App\Services\Egresos\Pagos::class)->registrarGasto($gasto);

        return $gasto->fresh();
    }

    public function test_cambiar_la_cuenta_mueve_el_movimiento_a_la_cuenta_nueva(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Impuestos']);
        $origen = CuentaTesoreria::factory()->create();
        $destino = CuentaTesoreria::factory()->create();
        $gasto = $this->gastoPagado($origen, $categoria);

        $this->actingAs($this->admin())->putJson(route('gastos.update', $gasto), [
            'fecha' => '2026-07-18',
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $destino->id,
            'pendiente' => false,
        ])->assertOk();

        $movimiento = $gasto->fresh()->movimientoTesoreria;
        $this->assertSame($destino->id, $movimiento->cuenta_tesoreria_id);
        $this->assertEqualsWithDelta(-5000.0, (float) $movimiento->monto, 0.001);
        $this->assertDatabaseCount('movimientos_tesoreria', 1);
    }

    public function test_cambiar_el_monto_y_la_fecha_actualiza_el_movimiento(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Impuestos']);
        $cuenta = CuentaTesoreria::factory()->create();
        $gasto = $this->gastoPagado($cuenta, $categoria);

        $this->actingAs($this->admin())->putJson(route('gastos.update', $gasto), [
            'fecha' => '2026-07-25',
            'monto' => 8000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ])->assertOk();

        $movimiento = $gasto->fresh()->movimientoTesoreria;
        $this->assertEqualsWithDelta(-8000.0, (float) $movimiento->monto, 0.001);
        $this->assertSame('2026-07-25', $movimiento->fecha->toDateString());
    }

    public function test_volver_el_gasto_a_pendiente_saca_el_movimiento_de_la_caja(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Impuestos']);
        $cuenta = CuentaTesoreria::factory()->create();
        $gasto = $this->gastoPagado($cuenta, $categoria);

        $this->actingAs($this->admin())->putJson(route('gastos.update', $gasto), [
            'fecha' => '2026-07-18',
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'pendiente' => true,
        ])->assertOk();

        $this->assertNull($gasto->fresh()->movimientoTesoreria);
    }
}
