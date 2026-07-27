<?php

namespace Tests\Unit;

use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\RankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function filtros(array $extra = []): array
    {
        return array_merge(['fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30'], $extra);
    }

    private function sembrarDatos(): array
    {
        $productoA = Producto::factory()->create(['nombre' => 'Producto A']);
        $productoB = Producto::factory()->create(['nombre' => 'Producto B']);

        $venta1 = Venta::factory()->create(['fecha_emision' => '2026-06-05']);
        VentaItem::factory()->create([
            'venta_id' => $venta1->id, 'producto_id' => $productoA->id,
            'cantidad' => 3, 'subtotal' => 1000, 'total' => 1210,
        ]);

        $venta2 = Venta::factory()->create(['fecha_emision' => '2026-06-10']);
        VentaItem::factory()->create([
            'venta_id' => $venta2->id, 'producto_id' => $productoA->id,
            'cantidad' => 2, 'subtotal' => 500, 'total' => 605,
        ]);

        $venta3 = Venta::factory()->create(['fecha_emision' => '2026-06-20']);
        VentaItem::factory()->create([
            'venta_id' => $venta3->id, 'producto_id' => $productoB->id,
            'cantidad' => 10, 'subtotal' => 2000, 'total' => 2420,
        ]);

        return compact('productoA', 'productoB');
    }

    public function test_las_cuatro_metricas_ordenan_desc_con_los_valores_correctos(): void
    {
        $this->sembrarDatos();
        $service = app(RankingService::class);

        $porCantidad = collect($service->ranking($this->filtros(['dato' => 'cantidad_productos'])));
        $this->assertSame('Producto B', $porCantidad->first()['producto']);
        $this->assertEquals(10.0, $porCantidad->first()['valor']);
        $this->assertEquals(5.0, $porCantidad->last()['valor']);

        $porTotalSinImpuestos = collect($service->ranking($this->filtros(['dato' => 'total_sin_impuestos'])));
        $this->assertSame('Producto B', $porTotalSinImpuestos->first()['producto']);
        $this->assertEquals(2000.0, $porTotalSinImpuestos->first()['valor']);
        $this->assertEquals(1500.0, $porTotalSinImpuestos->last()['valor']);

        $porTotal = collect($service->ranking($this->filtros(['dato' => 'total'])));
        $this->assertSame('Producto B', $porTotal->first()['producto']);
        $this->assertEquals(2420.0, $porTotal->first()['valor']);

        $porCantidadVentas = collect($service->ranking($this->filtros(['dato' => 'cantidad_ventas'])));
        $this->assertSame('Producto A', $porCantidadVentas->first()['producto']);
        $this->assertEquals(2.0, $porCantidadVentas->first()['valor']);
        $this->assertEquals(1.0, $porCantidadVentas->last()['valor']);
    }

    public function test_la_serie_agrupa_por_periodicidad(): void
    {
        $this->sembrarDatos();
        $service = app(RankingService::class);

        $mensual = $service->serie($this->filtros(['dato' => 'total_sin_impuestos', 'periodicidad' => 'mensual']));
        $this->assertCount(1, $mensual['labels']);
        $this->assertEquals(['2026-06'], $mensual['labels']);
        $this->assertEquals(3500.0, $mensual['valores'][0]);

        $diaria = $service->serie($this->filtros(['dato' => 'total_sin_impuestos', 'periodicidad' => 'diaria']));
        $this->assertCount(3, $diaria['labels']);
        $this->assertEquals(['2026-06-05', '2026-06-10', '2026-06-20'], $diaria['labels']);
    }
}
