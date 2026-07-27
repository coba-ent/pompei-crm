<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTesoreriaResumenTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_bloque_de_tesoreria_coincide_con_tesoreria_saldos(): void
    {
        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 1000]);
        $banco = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 2000]);
        app(Tesoreria::class)->registrarSaldoInicial($caja, 1000, now());
        app(Tesoreria::class)->registrarSaldoInicial($banco, 2000, now());

        $saldosEsperados = app(Tesoreria::class)->saldos();

        $resp = $this->get(route('dashboard.index'))->assertOk();

        $resp->assertViewHas('saldos', function ($saldos) use ($saldosEsperados) {
            return $saldos['disponible']['total'] === $saldosEsperados['disponible']['total']
                && $saldos['disponible']['cajas']['total'] === $saldosEsperados['disponible']['cajas']['total']
                && $saldos['disponible']['bancos']['total'] === $saldosEsperados['disponible']['bancos']['total'];
        });

        $resp->assertViewHas('saldos', function ($saldos) {
            return $saldos['disponible']['total'] === $saldos['disponible']['cajas']['total'] + $saldos['disponible']['bancos']['total'];
        });
    }

    public function test_movimientos_recientes_ordenados_desc_y_respetan_el_limite(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $tesoreria = app(Tesoreria::class);

        foreach (range(1, 12) as $i) {
            $tesoreria->registrarSaldoInicial($cuenta, 10, now()->copy()->subDays(20 - $i));
        }

        $resp = $this->get(route('dashboard.index'))->assertOk();

        $movimientos = $resp->viewData('movimientosRecientes');

        $this->assertLessThanOrEqual(10, count($movimientos));
        $fechas = array_column($movimientos->toArray(), 'fecha');
        $ordenadas = $fechas;
        rsort($ordenadas);
        $this->assertEquals($ordenadas, $fechas);
    }
}
