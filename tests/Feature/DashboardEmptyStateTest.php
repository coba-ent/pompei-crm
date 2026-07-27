<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Con la base de datos recién migrada (sin seeders de negocio), el dashboard
 * no debe romper: todos los bloques devuelven estado vacío (ceros) sin excepción
 * (SC-005/FR-012 — gap detectado en /speckit-analyze, F2).
 */
class DashboardEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_carga_sin_error_500(): void
    {
        $this->get(route('dashboard.index'))->assertOk();
    }

    public function test_kpis_devuelve_ceros_sin_excepcion(): void
    {
        $resp = $this->getJson(route('dashboard.kpis'))->assertOk()->json();

        $this->assertEquals(0.0, $resp['ventas_creadas']['valor']);
        $this->assertEquals(0, $resp['cantidad_ventas']['valor']);
        $this->assertNull($resp['ventas_creadas']['variacion_pct']);
    }

    public function test_totales_devuelve_ceros(): void
    {
        $resp = $this->getJson(route('dashboard.totales'))->assertOk()->json();

        $this->assertEquals(0.0, $resp['ventas']);
        $this->assertEquals(0.0, $resp['compras']);
    }

    public function test_grafico_mensual_devuelve_12_meses_en_cero(): void
    {
        $resp = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();

        $this->assertCount(12, $resp['labels']);
        $this->assertEquals(array_fill(0, 12, 0.0), $resp['series']['ventas']);
    }

    public function test_donas_devuelve_arreglos_vacios(): void
    {
        $resp = $this->getJson(route('dashboard.donas'))->assertOk()->json();

        $this->assertEmpty($resp['ventas']);
        $this->assertEmpty($resp['compras']);
        $this->assertEmpty($resp['gastos']);
    }

    public function test_rankings_devuelve_arreglos_vacios(): void
    {
        $resp = $this->getJson(route('dashboard.rankings'))->assertOk()->json();

        $this->assertEmpty($resp['clientes']);
        $this->assertEmpty($resp['productos']);
    }
}
