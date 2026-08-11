<?php

namespace Tests\Feature\Tesoreria;

use App\Models\CuentaTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec 055 (US1): Tesoreria::flujo() incluye tipo 'ingreso' dentro de la sección "Cobros". */
class FlujoCajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_movimiento_tipo_ingreso_dentro_del_rango_suma_a_cobros(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        app(Tesoreria::class)->registrarMovimiento(
            $cuenta, 1000.0, 'ingreso', fecha: Carbon::parse('2026-08-05'),
        );

        $flujo = app(Tesoreria::class)->flujo(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(1000.0, $flujo['total_cobros']);
        $this->assertSame(1000.0, collect($flujo['cobros'])->firstWhere('cuenta_id', $cuenta->id)['monto']);
    }

    public function test_movimiento_tipo_ingreso_fuera_del_rango_no_suma(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        app(Tesoreria::class)->registrarMovimiento(
            $cuenta, 1000.0, 'ingreso', fecha: Carbon::parse('2026-07-01'),
        );

        $flujo = app(Tesoreria::class)->flujo(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(0.0, $flujo['total_cobros']);
        $this->assertEmpty($flujo['cobros']);
    }
}
