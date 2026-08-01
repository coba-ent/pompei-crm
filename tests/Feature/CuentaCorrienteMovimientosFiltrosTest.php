<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 029 US2 — filtros de "Movimientos": Operación, Cliente y rango de fechas.
 */
class CuentaCorrienteMovimientosFiltrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtrar_por_operacion_cobro_solo_devuelve_filas_de_cobro(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 300]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'cobro',
        ]))->assertOk()->json();

        $this->assertNotEmpty($resp['data']);
        foreach ($resp['data'] as $fila) {
            $this->assertEquals('cobro', $fila['operacion']);
        }
    }

    public function test_filtrar_por_cliente_id_acota_correctamente(): void
    {
        $c1 = Cliente::factory()->create();
        $c2 = Cliente::factory()->create();
        Venta::factory()->create(['cliente_id' => $c1->id, 'total' => 1000]);
        Venta::factory()->create(['cliente_id' => $c2->id, 'total' => 2000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'cliente_id' => $c1->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals($c1->id, $resp['data'][0]['cliente_id']);
    }

    public function test_filtrar_por_rango_de_fechas_excluye_operaciones_fuera_de_rango(): void
    {
        $cliente = Cliente::factory()->create();
        Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000, 'fecha_emision' => '2026-01-10']);
        Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 2000, 'fecha_emision' => '2026-06-10']);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals(2000, (float) $resp['data'][0]['total_venta']);
    }
}
