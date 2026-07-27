<?php

namespace Tests\Unit;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\MovimientoTesoreria;
use App\Models\Venta;
use App\Services\PanelControl\PanelControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PanelControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PanelControlService
    {
        return app(PanelControlService::class);
    }

    private function junio(): array
    {
        return ['fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30'];
    }

    // ---------------- KPIs (US1) ----------------

    public function test_kpis_calcula_ventas_promedio_cantidad_y_resultado(): void
    {
        // Período actual (junio): 2 ventas = 400, 1 compra = 100, 1 gasto = 50.
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100]);
        Venta::factory()->create(['fecha_emision' => '2026-06-20', 'total' => 300]);
        Compra::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 100]);
        Gasto::factory()->create(['fecha' => '2026-06-12', 'importe' => 50]);
        // Período anterior (mayo): 1 venta = 200.
        Venta::factory()->create(['fecha_emision' => '2026-05-15', 'total' => 200]);

        $kpis = $this->service()->kpis($this->junio());

        $this->assertEquals(400.0, $kpis['ventas_creadas']['valor']);
        $this->assertEquals(2, $kpis['cantidad_ventas']['valor']);
        $this->assertEquals(200.0, $kpis['venta_promedio']['valor']);
        // Resultado = (400 + 0) − (100 + 50) = 250.
        $this->assertEquals(250.0, $kpis['resultado']['valor']);
        // Ventas 400 vs 200 anterior → +100%.
        $this->assertEquals(100.0, $kpis['ventas_creadas']['variacion_pct']);
    }

    public function test_venta_promedio_sin_ventas_es_cero(): void
    {
        $kpis = $this->service()->kpis($this->junio());

        $this->assertEquals(0.0, $kpis['venta_promedio']['valor']);
        $this->assertEquals(0, $kpis['cantidad_ventas']['valor']);
    }

    public function test_variacion_pct_maneja_division_por_cero(): void
    {
        $svc = $this->service();

        $this->assertSame(0.0, $svc->variacionPct(0, 0));
        $this->assertNull($svc->variacionPct(100, 0), 'Anterior 0 y actual > 0 → null ("—").');
        $this->assertEquals(50.0, $svc->variacionPct(150, 100));
        $this->assertEquals(-50.0, $svc->variacionPct(50, 100));
    }

    public function test_periodo_anterior_tiene_la_misma_longitud(): void
    {
        $anterior = $this->service()->periodoAnterior($this->junio());

        // Junio tiene 30 días → anterior de 30 días, contiguo (02/05 al 31/05).
        $this->assertEquals('2026-05-02', $anterior['fecha_desde']);
        $this->assertEquals('2026-05-31', $anterior['fecha_hasta']);
    }

    // ---------------- Totales (US2) ----------------

    public function test_totales_del_periodo_con_colores(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 400]);
        Compra::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 100]);
        Gasto::factory()->create(['fecha' => '2026-06-12', 'importe' => 50]);

        $totales = $this->service()->totales($this->junio());

        $this->assertEquals(400.0, $totales['ventas']['total']);
        $this->assertEquals(0.0, $totales['otros_ingresos']['total']);
        $this->assertEquals(100.0, $totales['compras']['total']);
        $this->assertEquals(50.0, $totales['gastos']['total']);
        $this->assertEquals('verde', $totales['ventas']['color']);
        $this->assertEquals('rojo', $totales['compras']['color']);
    }

    // ---------------- Evolución 12 meses (US2, D-006) ----------------

    public function test_evolucion_siempre_devuelve_12_meses_con_ceros(): void
    {
        Carbon::setTestNow('2026-06-15');
        Venta::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 999]);

        $evolucion = $this->service()->evolucion();

        $this->assertCount(12, $evolucion['meses']);
        $this->assertCount(12, $evolucion['series']['ventas']);
        $this->assertEquals('Jun 2026', $evolucion['meses'][11]);
        $this->assertEquals('Jul 2025', $evolucion['meses'][0]);
        // El mes actual tiene la venta; el resto en cero (FR-009).
        $this->assertEquals(999.0, $evolucion['series']['ventas'][11]);
        $this->assertEquals(0.0, $evolucion['series']['ventas'][0]);

        Carbon::setTestNow();
    }

    // ---------------- Tesorería (US3) ----------------

    public function test_tesoreria_consolida_disponible_igual_cajas_mas_bancos(): void
    {
        CuentaTesoreria::factory()->create(['tipo' => 'caja', 'saldo_inicial' => 1000]);
        CuentaTesoreria::factory()->create(['tipo' => 'banco', 'saldo_inicial' => 5000]);

        $tes = $this->service()->tesoreria();

        $this->assertEquals(1000.0, $tes['total_cajas']);
        $this->assertEquals(5000.0, $tes['total_bancos']);
        $this->assertEquals(6000.0, $tes['total_disponible']);
        $this->assertEquals($tes['total_cajas'] + $tes['total_bancos'], $tes['total_disponible']);
    }

    public function test_tesoreria_lista_ultimos_movimientos_con_signo(): void
    {
        $caja = CuentaTesoreria::factory()->create(['tipo' => 'caja', 'saldo_inicial' => 0]);
        // Entrada pura (sólo destino) → +.
        MovimientoTesoreria::factory()->create([
            'cuenta_origen_id' => null, 'cuenta_destino_id' => $caja->id,
            'fecha' => '2026-06-10', 'monto' => 500,
        ]);
        // Salida pura (sólo origen) → −.
        MovimientoTesoreria::factory()->create([
            'cuenta_origen_id' => $caja->id, 'cuenta_destino_id' => null,
            'fecha' => '2026-06-11', 'monto' => 200,
        ]);

        $movs = $this->service()->tesoreria()['ultimos_movimientos'];

        $this->assertCount(2, $movs);
        // Ordenados por fecha desc → primero la salida.
        $this->assertEquals('-', $movs[0]['signo']);
        $this->assertEquals(-200.0, $movs[0]['monto']);
        $this->assertEquals('+', $movs[1]['signo']);
        $this->assertEquals(500.0, $movs[1]['monto']);
    }

    // ---------------- Aging (US4, D-005) ----------------

    public function test_aging_clasifica_por_antiguedad_y_suma_igual_al_total(): void
    {
        Carbon::setTestNow('2026-06-15');

        // A vencer (futuro).
        Venta::factory()->create(['fecha_emision' => '2026-06-01', 'total' => 100, 'fecha_vencimiento_cobro' => '2026-07-01']);
        // 0 a 30 (vencido hace 14 días).
        Venta::factory()->create(['fecha_emision' => '2026-05-01', 'total' => 200, 'fecha_vencimiento_cobro' => '2026-06-01']);
        // 61 a 90 (vencido hace ~75 días).
        Venta::factory()->create(['fecha_emision' => '2026-03-01', 'total' => 300, 'fecha_vencimiento_cobro' => '2026-04-01']);
        // 90+ (vencido hace ~165 días).
        Venta::factory()->create(['fecha_emision' => '2026-01-01', 'total' => 400, 'fecha_vencimiento_cobro' => '2026-01-01']);

        $cobrar = $this->service()->pendientes($this->junio())['ventas_a_cobrar'];

        $this->assertEquals(100.0, $cobrar['aging']['a_vencer']);
        $this->assertEquals(200.0, $cobrar['aging']['0_30']);
        $this->assertEquals(300.0, $cobrar['aging']['61_90']);
        $this->assertEquals(400.0, $cobrar['aging']['90_mas']);
        $this->assertEquals(1000.0, $cobrar['total']);
        // FR-014: la suma de los tramos iguala el total.
        $this->assertEquals(
            array_sum($cobrar['aging']),
            $cobrar['total']
        );

        Carbon::setTestNow();
    }

    public function test_aging_excluye_ventas_totalmente_cobradas(): void
    {
        $venta = Venta::factory()->create([
            'fecha_emision' => '2026-06-01', 'total' => 100,
            'fecha_vencimiento_cobro' => '2026-06-01', 'estado_cobro' => 'cobrado',
        ]);
        \App\Models\Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 100]);

        $cobrar = $this->service()->pendientes($this->junio())['ventas_a_cobrar'];

        $this->assertEquals(0.0, $cobrar['total']);
    }
}
