<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class DashboardNeteoHelpersTest extends TestCase
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

    private function montoNeto(Carbon $desde, Carbon $hasta): float
    {
        $controller = app(DashboardController::class);
        $metodo = new ReflectionMethod(DashboardController::class, 'montoNetoQuery');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta);
    }

    public function test_venta_sin_notas_devuelve_el_total_bruto(): void
    {
        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);

        $this->assertEquals(1000.0, $this->montoNeto(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_venta_con_nc_total_queda_en_piso_cero(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 1000, 'fecha_emision' => '2026-06-06',
        ]);

        $this->assertEquals(0.0, $this->montoNeto(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_venta_con_nc_parcial_resta_el_monto_de_la_nota(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 300, 'fecha_emision' => '2026-06-06',
        ]);

        $this->assertEquals(700.0, $this->montoNeto(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_venta_con_nd_suma_el_monto_de_la_nota(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'debito', 'monto' => 100, 'fecha_emision' => '2026-06-06',
        ]);

        $this->assertEquals(1100.0, $this->montoNeto(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_nc_de_periodo_distinto_se_resta_sin_piso_del_periodo_de_la_nota(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-05-20', 'total' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 1000, 'fecha_emision' => '2026-06-06',
        ]);

        // Mes de la venta (mayo): queda en bruto, la nota no aparece ahí.
        $this->assertEquals(1000.0, $this->montoNeto(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')));
        // Mes de la nota (junio): se resta el monto de la nota, sin base de venta que la acote.
        $this->assertEquals(-1000.0, $this->montoNeto(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
    }

    public function test_montoNetoPorCategoriaQuery_agrupa_por_categoria_con_neteo(): void
    {
        $controller = app(DashboardController::class);
        $metodo = new ReflectionMethod(DashboardController::class, 'montoNetoPorCategoriaQuery');
        $metodo->setAccessible(true);

        $categoriaA = \App\Models\Categoria::factory()->create(['activo' => true]);
        $ventaA = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000, 'categoria_id' => $categoriaA->id]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $ventaA->id, 'tipo' => 'credito', 'monto' => 400, 'fecha_emision' => '2026-06-06',
        ]);
        Venta::factory()->create(['fecha_emision' => '2026-06-07', 'total' => 500, 'categoria_id' => null]);

        $resultado = $metodo->invoke($controller, Venta::class, 'venta_id', 'fecha_emision', Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertEquals(600.0, $resultado[$categoriaA->id]);
        $this->assertEquals(500.0, $resultado['sin_categoria']);
    }
}
