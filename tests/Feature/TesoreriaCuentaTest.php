<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\Rol;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US2 — Administrar cuentas de tesorería. FR-001/002/003/004/006/007/008,
 * SC-004/SC-007 (Principio IV: es dinero).
 */
class TesoreriaCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_alta_con_saldo_inicial_genera_un_movimiento(): void
    {
        $response = $this->postJson(route('tesoreria.cuentas.store'), [
            'nombre' => 'Caja Chica Prueba',
            'tipo' => 'efectivo',
            'saldo_inicial' => 1000,
            'saldo_inicial_fecha' => now()->toDateString(),
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $cuenta = CuentaTesoreria::where('nombre', 'Caja Chica Prueba')->firstOrFail();
        $this->assertSame(1, $cuenta->movimientos()->count());
        $this->assertSame('saldo_inicial', $cuenta->movimientos()->first()->tipo);
        $this->assertSame(1000.0, $cuenta->saldoA());
    }

    public function test_alta_con_saldo_cero_no_genera_movimiento(): void
    {
        $this->postJson(route('tesoreria.cuentas.store'), [
            'nombre' => 'Sin saldo',
            'tipo' => 'banco',
            'saldo_inicial' => 0,
            'saldo_inicial_fecha' => now()->toDateString(),
        ])->assertCreated();

        $cuenta = CuentaTesoreria::where('nombre', 'Sin saldo')->firstOrFail();
        $this->assertSame(0, $cuenta->movimientos()->count());
    }

    public function test_update_no_cambia_el_tipo(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create(['nombre' => 'Original']);

        $this->putJson(route('tesoreria.cuentas.update', $cuenta), [
            'nombre' => 'Modificada',
            'saldo_inicial' => 500,
            'saldo_inicial_fecha' => now()->toDateString(),
            'visible' => true,
        ])->assertOk()->assertJsonPath('ok', true);

        $cuenta->refresh();
        $this->assertSame('Modificada', $cuenta->nombre);
        $this->assertSame('banco', $cuenta->tipo);
    }

    public function test_ocultar_cuenta_la_quita_del_scope_visibles(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create(['visible' => true]);

        $this->putJson(route('tesoreria.cuentas.update', $cuenta), [
            'nombre' => $cuenta->nombre,
            'saldo_inicial' => $cuenta->saldo_inicial,
            'saldo_inicial_fecha' => now()->toDateString(),
            'visible' => false,
        ])->assertOk();

        $this->assertFalse($cuenta->fresh()->visible);
        $this->assertFalse(CuentaTesoreria::visibles()->get()->contains('id', $cuenta->id));
    }

    public function test_cuenta_del_sistema_no_editable_ni_eliminable(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('a_cobrar')->sistema()->create(['nombre' => 'Cheque de Terceros']);

        $this->putJson(route('tesoreria.cuentas.update', $cuenta), [
            'nombre' => 'Otro nombre',
            'saldo_inicial' => 0,
            'saldo_inicial_fecha' => now()->toDateString(),
        ])->assertStatus(422);

        $this->assertSame('Cheque de Terceros', $cuenta->fresh()->nombre);

        $this->deleteJson(route('tesoreria.cuentas.destroy', $cuenta))->assertStatus(422);
        $this->assertDatabaseHas('cuentas_tesoreria', ['id' => $cuenta->id]);
    }

    public function test_destroy_bloqueado_con_operaciones_y_permitido_sin_ellas(): void
    {
        $servicio = app(Tesoreria::class);

        $conMovimientos = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $servicio->registrarMovimiento($conMovimientos, 100, 'cobro', fecha: now());

        $response = $this->deleteJson(route('tesoreria.cuentas.destroy', $conMovimientos));
        $response->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseHas('cuentas_tesoreria', ['id' => $conMovimientos->id]);

        $sinMovimientos = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $this->deleteJson(route('tesoreria.cuentas.destroy', $sinMovimientos))
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('cuentas_tesoreria', ['id' => $sinMovimientos->id]);
    }

    public function test_destroy_permitido_con_solo_saldo_inicial(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $servicio->registrarSaldoInicial($cuenta, 500, now());

        $this->deleteJson(route('tesoreria.cuentas.destroy', $cuenta))
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('cuentas_tesoreria', ['id' => $cuenta->id]);
    }
}
