<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 — Otros Ingresos: impacto en Tesorería salvo "pendiente" (SC-006). */
class OtroIngresoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_ingreso_no_pendiente_genera_movimiento_e_impacta_el_saldo(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'ingreso']);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('otros-ingresos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 500,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame(500.0, $cuenta->saldoA());
        $otroIngreso = OtroIngreso::firstOrFail();
        $this->assertNotNull($otroIngreso->movimientoTesoreria);
    }

    public function test_ingreso_pendiente_no_impacta_el_saldo(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'ingreso']);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('otros-ingresos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 500,
            'categoria_id' => $categoria->id,
            'pendiente' => true,
        ])->assertCreated();

        $this->assertSame(0.0, $cuenta->saldoA());
        $otroIngreso = OtroIngreso::firstOrFail();
        $this->assertNull($otroIngreso->movimientoTesoreria);
    }

    public function test_conciliar_un_pendiente_genera_el_movimiento(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'ingreso']);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $otroIngreso = OtroIngreso::factory()->pendiente()->create(['categoria_id' => $categoria->id]);

        $this->putJson(route('otros-ingresos.update', $otroIngreso), [
            'fecha' => $otroIngreso->fecha->toDateString(),
            'monto' => (float) $otroIngreso->monto,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame((float) $otroIngreso->monto, $cuenta->saldoA());
        $this->assertNotNull($otroIngreso->fresh()->movimientoTesoreria);
    }

    public function test_eliminar_revierte_el_movimiento(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'ingreso']);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('otros-ingresos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 500,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ])->assertCreated();

        $otroIngreso = OtroIngreso::firstOrFail();

        $this->deleteJson(route('otros-ingresos.destroy', $otroIngreso))->assertOk();

        $this->assertSame(0.0, $cuenta->saldoA());
        $this->assertSoftDeleted('otros_ingresos', ['id' => $otroIngreso->id]);
    }
}
