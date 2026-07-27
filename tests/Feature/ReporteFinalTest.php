<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteFinalTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_endpoint_data_devuelve_el_shape_de_cuentas_y_total_general(): void
    {
        $caja = CuentaTesoreria::factory()->create(['nombre' => 'Caja']);
        $venta = Venta::factory()->create();
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $caja->id,
            'fecha' => '2026-06-10', 'monto' => 1000,
        ]);

        $resp = $this->getJson(route('informes.reporte-final.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertArrayHasKey('cuentas', $resp);
        $this->assertArrayHasKey('total_general', $resp);

        $fila = collect($resp['cuentas'])->firstWhere('cuenta', 'Caja');
        $this->assertNotNull($fila);
        $this->assertEquals(1000.0, $fila['ventas_cobradas']);
        $this->assertEquals(1000.0, $resp['total_general']['ingresos']);
    }

    public function test_cuentas_sin_movimientos_se_omiten_del_listado(): void
    {
        CuentaTesoreria::factory()->create(['nombre' => 'Sin Movimientos']);

        $resp = $this->getJson(route('informes.reporte-final.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertNull(collect($resp['cuentas'])->firstWhere('cuenta', 'Sin Movimientos'));
    }

    public function test_export_csv_refleja_el_total_general_de_la_pantalla(): void
    {
        $caja = CuentaTesoreria::factory()->create(['nombre' => 'Caja Export']);
        $venta = Venta::factory()->create();
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $caja->id,
            'fecha' => '2026-06-10', 'monto' => 4000,
        ]);

        $pantalla = $this->getJson(route('informes.reporte-final.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.reporte-final.csv', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $contenido = $csv->streamedContent();
        $this->assertStringContainsString('Caja Export', $contenido);
        $this->assertStringContainsString(
            number_format($pantalla['total_general']['ingresos'], 2, ',', ''),
            str_replace("\r\n", '', $contenido)
        );
    }
}
