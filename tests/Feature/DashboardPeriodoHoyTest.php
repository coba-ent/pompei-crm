<?php

namespace Tests\Feature;

use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardPeriodoHoyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_periodo_hoy_filtra_kpis_solo_a_operaciones_de_hoy(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-15', 'total' => 1000]);
        Venta::factory()->create(['fecha_emision' => '2026-06-14', 'total' => 500]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'hoy']))->assertOk()->json();

        $this->assertEquals(1000.0, $resp['ventas_creadas']['valor']);
        $this->assertEquals(1, $resp['cantidad_ventas']['valor']);
    }

    public function test_periodo_hoy_filtra_totales_y_donas_solo_a_operaciones_de_hoy(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-15', 'total' => 1000]);
        Venta::factory()->create(['fecha_emision' => '2026-06-14', 'total' => 500]);

        $totales = $this->getJson(route('dashboard.totales', ['periodo' => 'hoy']))->assertOk()->json();
        $this->assertEquals(1000.0, $totales['ventas']);

        $donas = $this->getJson(route('dashboard.donas', ['periodo' => 'hoy']))->assertOk()->json();
        $this->assertEquals(1000.0, array_sum(array_column($donas['ventas'], 'monto')));
    }

    public function test_variacion_de_hoy_compara_contra_ayer(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-15', 'total' => 1500]);
        Venta::factory()->create(['fecha_emision' => '2026-06-14', 'total' => 1000]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'hoy']))->assertOk()->json();

        $this->assertEquals(50.0, $resp['ventas_creadas']['variacion_pct']);
    }

    public function test_variacion_de_hoy_es_null_sin_datos_de_ayer(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-15', 'total' => 1500]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'hoy']))->assertOk()->json();

        $this->assertNull($resp['ventas_creadas']['variacion_pct']);
    }

    public function test_grafico_mensual_no_cambia_con_periodo_hoy(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-15', 'total' => 1000]);

        $sinPeriodo = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();
        $conPeriodoHoy = $this->getJson(route('dashboard.grafico-mensual', ['periodo' => 'hoy']))->assertOk()->json();

        $this->assertEquals($sinPeriodo, $conPeriodoHoy);
    }
}
