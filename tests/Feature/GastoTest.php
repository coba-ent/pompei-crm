<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 — Gasto: pendiente no impacta (SC-005), conciliar genera el movimiento, eliminar revierte sin Observer. */
class GastoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_gasto_no_pendiente_impacta_el_saldo_en_negativo(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'gasto']);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        $this->postJson(route('gastos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame(-5000.0, $cuenta->saldoA());
    }

    public function test_gasto_pendiente_no_impacta_ningun_saldo(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'gasto']);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        $this->postJson(route('gastos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'pendiente' => true,
        ])->assertCreated();

        $gasto = Gasto::firstOrFail();
        $this->assertTrue($gasto->pendiente);
        $this->assertNull($gasto->movimientoTesoreria);
        $this->assertSame(0.0, $cuenta->saldoA());
    }

    public function test_conciliar_gasto_pendiente_genera_el_movimiento(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'gasto']);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        $this->postJson(route('gastos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'pendiente' => true,
        ])->assertCreated();

        $gasto = Gasto::firstOrFail();

        $this->putJson(route('gastos.update', $gasto), [
            'fecha' => now()->toDateString(),
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ])->assertOk();

        $this->assertSame(-5000.0, $cuenta->saldoA());
        $this->assertNotNull($gasto->fresh()->movimientoTesoreria);
    }

    public function test_eliminar_gasto_revierte_el_movimiento_de_tesoreria(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'gasto']);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        $this->postJson(route('gastos.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
        ])->assertCreated();

        $gasto = Gasto::firstOrFail();
        $this->assertSame(-5000.0, $cuenta->saldoA());

        $this->deleteJson(route('gastos.destroy', $gasto))->assertOk();

        $this->assertSame(0.0, $cuenta->saldoA());
        $this->assertSoftDeleted('gastos', ['id' => $gasto->id]);
    }
}
