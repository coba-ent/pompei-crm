<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

class DashboardDonasTest extends TestCase
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

    public function test_la_dona_de_ventas_suma_100_por_ciento_entre_sus_porciones(): void
    {
        $catA = Categoria::factory()->create(['tipo' => 'venta']);
        $catB = Categoria::factory()->create(['tipo' => 'venta']);

        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 300, 'categoria_id' => $catA->id]);
        Venta::factory()->create(['fecha_emision' => '2026-06-10', 'total' => 700, 'categoria_id' => $catB->id]);

        $resp = $this->getJson(route('dashboard.donas', ['periodo' => 'mes_actual']))->assertOk()->json();

        $totalPorciones = array_sum(array_column($resp['ventas'], 'monto'));
        $this->assertEquals(1000.0, $totalPorciones);
    }

    public function test_categoria_inactiva_con_ventas_historicas_se_agrupa_bajo_sin_categoria(): void
    {
        $catInactiva = Categoria::factory()->create(['tipo' => 'venta', 'activo' => false]);

        Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 400, 'categoria_id' => $catInactiva->id]);
        Venta::factory()->create(['fecha_emision' => '2026-06-06', 'total' => 100, 'categoria_id' => null]);

        $resp = $this->getJson(route('dashboard.donas', ['periodo' => 'mes_actual']))->assertOk()->json();

        $sinCategoria = collect($resp['ventas'])->firstWhere('categoria', 'Sin categoría');
        $this->assertNotNull($sinCategoria);
        $this->assertEquals(500.0, $sinCategoria['monto']);
    }
}
