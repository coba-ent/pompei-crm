<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardNeteoNotasTest extends TestCase
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

    // --- Historia 1 (US1): Ventas ---

    public function test_us1_nc_total_del_mismo_periodo_deja_ventas_creadas_en_cero(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(0.0, $resp['ventas_creadas']['valor']);
    }

    public function test_us1_nc_parcial_resta_del_total(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(70000.0, $resp['ventas_creadas']['valor']);
    }

    public function test_us1_nd_suma_al_total(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'debito', 'monto' => 10000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(110000.0, $resp['ventas_creadas']['valor']);
    }

    public function test_us1_nc_de_periodo_cruzado_se_resta_del_mes_de_la_nota_no_del_mes_de_la_venta(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-05-20', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100000, 'fecha_emision' => '2026-06-06',
        ]);

        $respMesAnterior = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_anterior']))->assertOk()->json();
        $this->assertEquals(100000.0, $respMesAnterior['ventas_creadas']['valor']);

        $respMesActual = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();
        $this->assertEquals(-100000.0, $respMesActual['ventas_creadas']['valor']);
    }

    public function test_us1_resultado_y_venta_promedio_derivan_del_monto_neto(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(70000.0, $resp['venta_promedio']['valor']);
        $this->assertEquals(70000.0, $resp['resultado']['valor']);
    }

    public function test_us1_cantidad_de_ventas_no_cambia_con_venta_anulada_al_100_por_ciento(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(1, $resp['cantidad_ventas']['valor']);
    }

    // --- Historia 2 (US2): Compras ---

    public function test_us2_nc_de_compra_no_se_incluye_en_el_total_de_compras(): void
    {
        $compra = Compra::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 50000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito', 'monto' => 50000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.totales', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(0.0, $resp['compras']);
    }

    public function test_us2_nd_de_compra_suma_al_total_de_compras(): void
    {
        $compra = Compra::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 50000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'debito', 'monto' => 5000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.totales', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(55000.0, $resp['compras']);
    }

    // --- Historia 3 (US3): Gráfico Mensual y Donas ---

    public function test_us3_grafico_mensual_no_incluye_venta_anulada_al_100_por_ciento_en_el_mes_de_la_nota(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();

        $indiceJunio = array_search('2026-06', $resp['labels'], true);
        $this->assertEquals(0.0, $resp['series']['ventas'][$indiceJunio]);
    }

    public function test_us3_dona_de_ventas_por_categoria_no_incluye_la_porcion_anulada(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'venta']);
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000, 'categoria_id' => $categoria->id]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.donas', ['periodo' => 'mes_actual']))->assertOk()->json();

        $porcion = collect($resp['ventas'])->firstWhere('categoria', $categoria->nombre);
        $this->assertNotNull($porcion);
        $this->assertEquals(0.0, $porcion['monto']);
    }

    public function test_us3_dona_agrupa_bajo_sin_categoria_incluyendo_ajuste_de_nota(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 100000, 'categoria_id' => null]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30000, 'fecha_emision' => '2026-06-06',
        ]);

        $resp = $this->getJson(route('dashboard.donas', ['periodo' => 'mes_actual']))->assertOk()->json();

        $sinCategoria = collect($resp['ventas'])->firstWhere('categoria', 'Sin categoría');
        $this->assertNotNull($sinCategoria);
        $this->assertEquals(70000.0, $sinCategoria['monto']);
    }

    // --- Polish: regresión SC-005 (sin NC/ND, comportamiento idéntico al previo) ---

    public function test_sc005_sin_notas_los_endpoints_devuelven_el_monto_bruto_de_siempre(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        Venta::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 2000]);
        Compra::factory()->create(['fecha_emision' => '2026-06-12', 'total' => 800]);

        $kpis = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();
        $this->assertEquals(3000.0, $kpis['ventas_creadas']['valor']);

        $totales = $this->getJson(route('dashboard.totales', ['periodo' => 'mes_actual']))->assertOk()->json();
        $this->assertEquals(3000.0, $totales['ventas']);
        $this->assertEquals(800.0, $totales['compras']);

        $grafico = $this->getJson(route('dashboard.grafico-mensual'))->assertOk()->json();
        $indiceJunio = array_search('2026-06', $grafico['labels'], true);
        $this->assertEquals(3000.0, $grafico['series']['ventas'][$indiceJunio]);
        $this->assertEquals(800.0, $grafico['series']['compras'][$indiceJunio]);
    }
}
