<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\Rol;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * US5 — Informe Movimientos (flujo de caja). FR-026/FR-027/FR-028: Cobros =
 * tipo `cobro`, Pagos = tipo `pago`/`gasto` (los gastos pendientes no generan
 * movimiento de tesorería hasta pagarse, así que ya quedan excluidos por
 * construcción — datos sembrados manualmente, sin generadores todavía).
 */
class TesoreriaFlujoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_flujo_computa_cobros_pagos_y_resultado(): void
    {
        $servicio = app(Tesoreria::class);
        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create(['nombre' => 'Caja del Local']);
        $banco = CuentaTesoreria::factory()->tipo('banco')->create(['nombre' => 'Banco Galicia']);

        $servicio->registrarMovimiento($caja, 500, 'cobro', fecha: Carbon::parse('2026-06-10'));
        $servicio->registrarMovimiento($banco, 300, 'cobro', fecha: Carbon::parse('2026-06-15'));
        $servicio->registrarMovimiento($caja, -100, 'pago', fecha: Carbon::parse('2026-06-12'));
        $servicio->registrarMovimiento($banco, -50, 'gasto', fecha: Carbon::parse('2026-06-20'));
        // Fuera de rango: no debe contarse.
        $servicio->registrarMovimiento($caja, 999, 'cobro', fecha: Carbon::parse('2026-05-01'));

        $flujo = $servicio->flujo(now()->parse('2026-06-01'), now()->parse('2026-06-30'));

        $this->assertSame(800.0, $flujo['total_cobros']);
        $this->assertSame(150.0, $flujo['total_pagos']);
        $this->assertSame(650.0, $flujo['resultado']);
        $this->assertCount(2, $flujo['cobros']);
        $this->assertCount(2, $flujo['pagos']);
    }

    public function test_saldo_inicial_y_transferencias_no_cuentan_como_cobro_o_pago(): void
    {
        $servicio = app(Tesoreria::class);
        $a = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $b = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($a, 1000, Carbon::parse('2026-06-01'));
        $servicio->transferir($a, $b, 200, Carbon::parse('2026-06-05'), null);

        $flujo = $servicio->flujo(now()->parse('2026-06-01'), now()->parse('2026-06-30'));

        $this->assertEquals(0.0, $flujo['total_cobros']);
        $this->assertEquals(0.0, $flujo['total_pagos']);
    }

    public function test_cuentas_activas_afecta_solo_el_total_no_el_desglose(): void
    {
        $servicio = app(Tesoreria::class);
        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $banco = CuentaTesoreria::factory()->tipo('banco')->create();

        $servicio->registrarMovimiento($caja, 500, 'cobro', fecha: Carbon::parse('2026-06-10'));
        $servicio->registrarMovimiento($banco, 300, 'cobro', fecha: Carbon::parse('2026-06-15'));

        $flujo = $servicio->flujo(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), [$caja->id]);

        // El total sólo refleja la cuenta activa...
        $this->assertSame(500.0, $flujo['total_cobros']);
        // ...pero el desglose sigue mostrando todas las cuentas.
        $this->assertCount(2, $flujo['cobros']);
    }

    public function test_endpoint_movimientos_data_responde_estructura_esperada(): void
    {
        $response = $this->getJson(route('tesoreria.movimientos.data', [
            'desde' => '2026-06-01', 'hasta' => '2026-06-30',
        ]));

        $response->assertOk()->assertJsonStructure([
            'total_cobros', 'total_pagos', 'resultado', 'cobros', 'pagos',
        ]);
    }

    public function test_endpoint_pdf_responde_ok(): void
    {
        $response = $this->get(route('tesoreria.movimientos.pdf', [
            'desde' => '2026-06-01', 'hasta' => '2026-06-30',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }
}
