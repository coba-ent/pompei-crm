<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Gasto;
use App\Models\OtroIngreso;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

class DashboardKpisTest extends TestCase
{
    use RefreshDatabase;
    use ActuaComoUsuarioConPermisos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUsuarioConTodosLosPermisosDashboard();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calcula_los_cuatro_kpis_del_mes_actual(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        Venta::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 2000]);
        OtroIngreso::factory()->create(['fecha' => '2026-06-08', 'monto' => 500, 'pendiente' => false]);
        Compra::factory()->create(['fecha_emision' => '2026-06-12', 'total' => 800]);
        Gasto::factory()->create(['fecha' => '2026-06-14', 'monto' => 300, 'pendiente' => false]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(3000.0, $resp['ventas_creadas']['valor']);
        $this->assertEquals(1500.0, $resp['venta_promedio']['valor']);
        $this->assertEquals(2, $resp['cantidad_ventas']['valor']);
        // Resultado = 3000 (ventas) + 500 (otro ingreso) - 800 (compra) - 300 (gasto) = 2400
        $this->assertEquals(2400.0, $resp['resultado']['valor']);
    }

    public function test_variacion_pct_correcta_con_datos_en_ambos_periodos(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1500]);
        Venta::factory()->create(['fecha_emision' => '2026-05-10', 'total' => 1000]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(50.0, $resp['ventas_creadas']['variacion_pct']);
    }

    public function test_variacion_pct_es_null_sin_datos_previos(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1500]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertNull($resp['ventas_creadas']['variacion_pct']);
        $this->assertNull($resp['resultado']['variacion_pct']);
    }

    public function test_resultado_excluye_otro_ingreso_y_gasto_pendientes(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        OtroIngreso::factory()->create(['fecha' => '2026-06-06', 'monto' => 5000, 'pendiente' => true]);
        Gasto::factory()->create(['fecha' => '2026-06-07', 'monto' => 3000, 'pendiente' => true]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(1000.0, $resp['resultado']['valor']);
    }

    public function test_excluye_ventas_soft_deleted(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        $venta->delete();

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(0.0, $resp['ventas_creadas']['valor']);
        $this->assertEquals(0, $resp['cantidad_ventas']['valor']);
    }
}
