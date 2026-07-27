<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_endpoint_data_devuelve_ranking_y_serie_para_cada_dato_y_periodicidad(): void
    {
        $producto = Producto::factory()->create(['nombre' => 'Producto Test']);
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-10']);
        VentaItem::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id,
            'cantidad' => 4, 'subtotal' => 800, 'total' => 968,
        ]);

        foreach (['cantidad_productos', 'total_sin_impuestos', 'total', 'cantidad_ventas'] as $dato) {
            foreach (['diaria', 'semanal', 'mensual'] as $periodicidad) {
                $resp = $this->getJson(route('informes.ranking.data', [
                    'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
                    'dato' => $dato, 'periodicidad' => $periodicidad,
                ]))->assertOk()->json();

                $this->assertArrayHasKey('ranking', $resp);
                $this->assertArrayHasKey('serie', $resp);
                $this->assertArrayHasKey('labels', $resp['serie']);
                $this->assertArrayHasKey('valores', $resp['serie']);
                $this->assertEquals('Producto Test', $resp['ranking'][0]['producto']);
            }
        }
    }
}
