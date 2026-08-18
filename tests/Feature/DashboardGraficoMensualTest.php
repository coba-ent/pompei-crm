<?php

namespace Tests\Feature;

use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

class DashboardGraficoMensualTest extends TestCase
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

    public function test_devuelve_exactamente_12_puntos_en_orden_cronologico(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        Venta::factory()->create(['fecha_emision' => '2025-08-10', 'total' => 500]);
        Venta::factory()->create(['fecha_emision' => '2026-01-20', 'total' => 700]);

        $resp = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();

        $this->assertCount(12, $resp['labels']);
        $this->assertEquals('2025-07', $resp['labels'][0]);
        $this->assertEquals('2026-06', $resp['labels'][11]);
        $this->assertCount(12, $resp['series']['ventas']);
    }

    public function test_mes_sin_operaciones_devuelve_cero_explicito(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);

        $resp = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();

        // 2026-05 no tiene ventas: debe aparecer con 0, no omitirse.
        $indiceMayo = array_search('2026-05', $resp['labels'], true);
        $this->assertNotFalse($indiceMayo);
        $this->assertEquals(0.0, $resp['series']['ventas'][$indiceMayo]);

        $indiceJunio = array_search('2026-06', $resp['labels'], true);
        $this->assertEquals(1000.0, $resp['series']['ventas'][$indiceJunio]);
    }
}
