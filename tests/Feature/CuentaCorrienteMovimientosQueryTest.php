<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 029 — CuentaCorrienteController::queryMovimientos() (research.md R2):
 * UNION de Venta/Cobro/Nota, ejercitado vía el endpoint movimientos.data.
 */
class CuentaCorrienteMovimientosQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_una_fila_por_venta_cobro_y_nota_con_las_columnas_esperadas(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 300]);
        $nota = NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 100]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $this->assertEquals(3, $resp['recordsTotal']);

        $filas = collect($resp['data'])->keyBy('operacion');

        $filaVenta = $filas['venta'];
        $this->assertEquals($venta->id, $filaVenta['id']);
        $this->assertEquals($cliente->id, $filaVenta['cliente_id']);
        $this->assertEquals(1000, (float) $filaVenta['total_venta']);
        $this->assertEquals(300, (float) $filaVenta['cobrado']);
        $this->assertEquals($venta->aCobrar(), (float) $filaVenta['a_cobrar']);
        $this->assertNull($filaVenta['medio_cobro']);

        $filaCobro = $filas['cobro'];
        $this->assertEquals($cobro->id, $filaCobro['id']);
        $this->assertEquals($cliente->id, $filaCobro['cliente_id']);
        $this->assertNull($filaCobro['total_venta']);
        $this->assertNotNull($filaCobro['medio_cobro']);

        $filaNota = $filas['nota_credito'];
        $this->assertEquals($nota->id, $filaNota['id']);
        $this->assertEquals($cliente->id, $filaNota['cliente_id']);
        $this->assertEquals($nota->descripcion, $filaNota['descripcion']);
        $this->assertNull($filaNota['total_venta']);
    }

    public function test_nota_de_debito_se_expone_como_operacion_nota_debito(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 500]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'debito', 'monto' => 50]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10,
        ]))->assertOk()->json();

        $operaciones = collect($resp['data'])->pluck('operacion')->all();
        $this->assertContains('nota_debito', $operaciones);
    }
}
