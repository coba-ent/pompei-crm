<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Venta;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 031 US1/US3 — `saldos.data` refleja el saldo inicial (SC-001) y el
 * Total General coincide con el mismo agregado que consume el Dashboard
 * (SC-002, spec 029 SC-003 verificado de nuevo tras el cambio).
 */
class CuentaCorrienteSaldoInicialEndpointTest extends TestCase
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

    public function test_cliente_con_solo_saldo_inicial_aparece_en_saldos_data(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals($cliente->id, $resp['data'][0]['cliente_id']);
        $this->assertEquals(50000.0, $resp['data'][0]['vencido_31_60']);
        $this->assertEquals(50000.0, $resp['data'][0]['total']);
    }

    public function test_total_general_de_saldos_data_coincide_con_aging_del_dashboard(): void
    {
        $c1 = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        $c2 = Cliente::factory()->create(['saldo_inicial' => 0]);
        Venta::factory()->create([
            'cliente_id' => $c2->id,
            'total' => 12000,
            'fecha_vto_cobro' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $totalGeneral = round(collect($resp['data'])->sum(fn ($fila) => (float) $fila['total']), 2);
        $totalDashboard = app(CuentaCorriente::class)->aging('cliente')['total'];

        $this->assertEquals($totalDashboard, $totalGeneral);
        $this->assertEquals(62000.0, $totalGeneral);
    }

    public function test_saldo_inicial_negativo_no_queda_excluido_y_muestra_total_negativo(): void
    {
        Cliente::factory()->create([
            'saldo_inicial' => -5000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals(-5000.0, $resp['data'][0]['total']);
    }
}
