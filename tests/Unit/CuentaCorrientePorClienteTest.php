<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 029 — CuentaCorriente::porCliente() reutiliza el mismo bucketing que
 * aging() (research.md R1) pero acumulando por cliente_id (FR-002).
 */
class CuentaCorrientePorClienteTest extends TestCase
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

    public function test_clasifica_por_cliente_en_el_bucket_correcto_y_excluye_a_los_cobrados(): void
    {
        $vencido = Cliente::factory()->create(['nombre' => 'Cliente Vencido']);
        $aVencer = Cliente::factory()->create(['nombre' => 'Cliente A Vencer']);
        $cobrado = Cliente::factory()->create(['nombre' => 'Cliente Cobrado']);

        Venta::factory()->create([
            'cliente_id' => $vencido->id,
            'total' => 1000,
            'fecha_vto_cobro' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        Venta::factory()->create([
            'cliente_id' => $aVencer->id,
            'total' => 500,
            'fecha_vto_cobro' => Carbon::today()->addDays(10)->toDateString(),
        ]);
        $ventaCobrada = Venta::factory()->create([
            'cliente_id' => $cobrado->id,
            'total' => 300,
            'fecha_vto_cobro' => Carbon::today()->subDays(5)->toDateString(),
        ]);
        Cobro::factory()->create(['venta_id' => $ventaCobrada->id, 'monto' => 300]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(1000.0, $porCliente[$vencido->id]['vencido_31_60']);
        $this->assertEquals(1000.0, $porCliente[$vencido->id]['total']);

        $this->assertEquals(500.0, $porCliente[$aVencer->id]['a_vencer']);
        $this->assertEquals(500.0, $porCliente[$aVencer->id]['total']);

        $this->assertFalse($porCliente->has($cobrado->id));
    }

    public function test_la_suma_de_totales_por_cliente_coincide_con_el_total_global_de_aging(): void
    {
        $c1 = Cliente::factory()->create();
        $c2 = Cliente::factory()->create();

        Venta::factory()->create([
            'cliente_id' => $c1->id,
            'total' => 1234.56,
            'fecha_vto_cobro' => Carbon::today()->subDays(100)->toDateString(),
        ]);
        Venta::factory()->create([
            'cliente_id' => $c2->id,
            'total' => 789.10,
            'fecha_vto_cobro' => Carbon::today()->addDays(5)->toDateString(),
        ]);

        $servicio = app(CuentaCorriente::class);
        $sumaPorCliente = $servicio->porCliente('cliente')->sum('total');
        $totalAging = $servicio->aging('cliente')['total'];

        $this->assertEquals($totalAging, round($sumaPorCliente, 2));
    }
}
