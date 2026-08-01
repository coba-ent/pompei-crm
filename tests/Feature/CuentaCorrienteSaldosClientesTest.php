<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 029 US1 — CuentaCorrienteController::saldosData() (SC-001/SC-003).
 */
class CuentaCorrienteSaldosClientesTest extends TestCase
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

    public function test_deuda_vencida_hace_45_dias_aparece_en_el_bucket_31_60(): void
    {
        $cliente = Cliente::factory()->create();
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 1000,
            'fecha_vto_cobro' => Carbon::today()->subDays(45)->toDateString(),
        ]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals(1000.0, $resp['data'][0]['vencido_31_60']);
        $this->assertEquals(1000.0, $resp['data'][0]['total']);
    }

    public function test_filtrar_por_cliente_id_acota_a_un_solo_cliente(): void
    {
        $c1 = Cliente::factory()->create();
        $c2 = Cliente::factory()->create();
        Venta::factory()->create(['cliente_id' => $c1->id, 'total' => 1000]);
        Venta::factory()->create(['cliente_id' => $c2->id, 'total' => 2000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'cliente_id' => $c1->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals($c1->id, $resp['data'][0]['cliente_id']);
    }

    public function test_ordenar_por_total_desc_devuelve_el_orden_esperado(): void
    {
        $chico = Cliente::factory()->create(['nombre' => 'Chico']);
        $grande = Cliente::factory()->create(['nombre' => 'Grande']);
        Venta::factory()->create(['cliente_id' => $chico->id, 'total' => 100]);
        Venta::factory()->create(['cliente_id' => $grande->id, 'total' => 9000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
            'order' => [['column' => 6, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'cliente_nombre', 'name' => 'cliente_nombre'],
                ['data' => 'a_vencer', 'name' => 'a_vencer'],
                ['data' => 'vencido_0_30', 'name' => 'vencido_0_30'],
                ['data' => 'vencido_31_60', 'name' => 'vencido_31_60'],
                ['data' => 'vencido_61_90', 'name' => 'vencido_61_90'],
                ['data' => 'vencido_mas_90', 'name' => 'vencido_mas_90'],
                ['data' => 'total', 'name' => 'total'],
            ],
        ]))->assertOk()->json();

        $this->assertEquals($grande->id, $resp['data'][0]['cliente_id']);
        $this->assertEquals($chico->id, $resp['data'][1]['cliente_id']);
    }
}
