<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 029 US2 — SC-002: el "A Cobrar" acumulado de las filas tipo Venta en
 * Movimientos coincide con el "Total" de Saldos Clientes para ese cliente.
 */
class CuentaCorrienteMovimientosClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_suma_de_a_cobrar_en_movimientos_coincide_con_total_de_saldos(): void
    {
        $cliente = Cliente::factory()->create();
        $v1 = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $v2 = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 2000]);
        Cobro::factory()->create(['venta_id' => $v2->id, 'monto' => 500]);

        $movimientos = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $sumaACobrar = collect($movimientos['data'])
            ->where('operacion', 'venta')
            ->sum(fn ($fila) => (float) $fila['a_cobrar']);

        $saldos = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $this->assertEquals(round($v1->aCobrar() + $v2->aCobrar(), 2), round($sumaACobrar, 2));
        $this->assertEquals($saldos['data'][0]['total'], round($sumaACobrar, 2));
    }
}
