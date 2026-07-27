<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Services\Gastos\Gastos;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoEdicionBajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_importe_de_un_pagado_reajusta_la_cuenta(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Insumos']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);

        $gasto = app(Gastos::class)->crear([
            'importe' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ]);
        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        $this->putJson(route('gastos.update', $gasto), [
            'importe' => 8000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->assertSame(2000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertEquals(8000.00, (float) $gasto->fresh()->importe);
    }

    public function test_cambiar_cuenta_mueve_el_importe_entre_cuentas(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Insumos']);
        $cuentaA = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);
        $cuentaB = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $gasto = app(Gastos::class)->crear([
            'importe' => 3000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuentaA->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ]);

        $this->putJson(route('gastos.update', $gasto), [
            'importe' => 3000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuentaB->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->assertSame(10000.0, app(Tesoreria::class)->saldoDe($cuentaA->fresh()));
        $this->assertSame(-2000.0, app(Tesoreria::class)->saldoDe($cuentaB->fresh()));
    }

    public function test_eliminar_un_gasto_pagado_revierte_y_soft_delete(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Insumos']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);

        $gasto = app(Gastos::class)->crear([
            'importe' => 4000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ]);
        $this->assertSame(6000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        $this->deleteJson(route('gastos.destroy', $gasto))->assertOk();

        $this->assertSame(10000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertSoftDeleted('gastos', ['id' => $gasto->id]);
    }

    public function test_eliminar_un_gasto_pendiente_no_toca_tesoreria(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Insumos']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);

        $gasto = app(Gastos::class)->crear([
            'importe' => 4000,
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ]);

        $this->deleteJson(route('gastos.destroy', $gasto))->assertOk();

        $this->assertSame(10000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertDatabaseCount('movimientos_tesoreria', 0);
        $this->assertSoftDeleted('gastos', ['id' => $gasto->id]);
    }
}
