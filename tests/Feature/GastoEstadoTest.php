<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoEstadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pendiente_a_pagado_crea_movimiento_y_pagado_a_pendiente_lo_revierte(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Mantenimiento']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);

        $this->postJson(route('gastos.store'), [
            'importe' => 4000,
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertOk();

        $gasto = Gasto::first();
        $this->assertSame(10000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        // pendiente → pagado: crea el movimiento (cuenta baja).
        $this->putJson(route('gastos.update', $gasto), [
            'importe' => 4000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->assertSame(6000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'origen_type' => Gasto::class,
            'origen_id' => $gasto->id,
            'tipo' => 'pago',
            'revierte_a_id' => null,
        ]);

        // pagado → pendiente: reversa (cuenta recupera el importe).
        $this->putJson(route('gastos.update', $gasto), [
            'importe' => 4000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => null,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->assertSame(10000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertSame('pendiente', $gasto->fresh()->estado);

        // Sin huérfanos: exactamente 2 movimientos (el original + su reversa).
        $this->assertDatabaseCount('movimientos_tesoreria', 2);
    }
}
