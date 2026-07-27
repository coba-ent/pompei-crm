<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeComprasTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtra_por_fecha_y_proveedor_y_calcula_totales(): void
    {
        $proveedorA = Proveedor::factory()->create(['nombre' => 'Proveedor A']);
        $proveedorB = Proveedor::factory()->create(['nombre' => 'Proveedor B']);

        Compra::factory()->create([
            'proveedor_id' => $proveedorA->id, 'fecha_emision' => '2026-06-05', 'subtotal' => 1000, 'total' => 1210,
        ]);
        Compra::factory()->create([
            'proveedor_id' => $proveedorB->id, 'fecha_emision' => '2026-06-10', 'subtotal' => 2000, 'total' => 2420,
        ]);
        // Fuera de rango.
        Compra::factory()->create([
            'proveedor_id' => $proveedorA->id, 'fecha_emision' => '2026-05-01', 'subtotal' => 500, 'total' => 605,
        ]);

        $resp = $this->getJson(route('informes.compras.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);
        $this->assertEquals(3630.0, $resp['total_general']['total']);

        $respFiltrado = $this->getJson(route('informes.compras.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30', 'proveedor_id' => $proveedorA->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $respFiltrado['data']);
        $this->assertEquals(1210.0, $respFiltrado['total_general']['total']);
    }

    public function test_excluye_compras_soft_deleted(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-06-05', 'subtotal' => 1000, 'total' => 1210,
        ]);
        $compra->delete();

        $resp = $this->getJson(route('informes.compras.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(0, $resp['data']);
    }

    public function test_export_csv_refleja_el_total_de_la_pantalla(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Proveedor Export']);
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-06-05', 'subtotal' => 1000, 'total' => 1210,
        ]);

        $pantalla = $this->getJson(route('informes.compras.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.compras.csv', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $contenido = str_replace("\r\n", '', $csv->streamedContent());
        $this->assertStringContainsString('Proveedor Export', $contenido);
        $this->assertStringContainsString(
            number_format($pantalla['total_general']['total'], 2, ',', ''),
            $contenido
        );
    }
}
