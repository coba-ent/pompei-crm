<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 031 US2 — fila sintética "Saldo Inicial" en `movimientos.data`
 * (FR-008/FR-009), filtrable por Operación (Acceptance Scenario 4).
 */
class CuentaCorrienteMovimientosSaldoInicialTest extends TestCase
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

    public function test_cliente_con_saldo_inicial_y_venta_genera_dos_filas_en_movimientos(): void
    {
        $fecha = Carbon::today()->subDays(45)->toDateString();
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => $fecha,
        ]);
        Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 10000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);

        $filaSaldoInicial = collect($resp['data'])->firstWhere('operacion', 'saldo_inicial');
        $this->assertNotNull($filaSaldoInicial);
        $this->assertEquals($fecha, $filaSaldoInicial['fecha_emision']);
        $this->assertEquals(50000.0, (float) $filaSaldoInicial['a_cobrar']);
        $this->assertNull($filaSaldoInicial['categoria']);
        $this->assertNull($filaSaldoInicial['nro_comprobante']);
        $this->assertNull($filaSaldoInicial['medio_cobro']);
    }

    public function test_suma_de_a_cobrar_en_movimientos_incluye_el_saldo_inicial_y_coincide_con_saldos(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 10000,
            'fecha_vto_cobro' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $movimientos = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $sumaACobrar = collect($movimientos['data'])->sum(fn ($fila) => (float) $fila['a_cobrar']);

        $saldos = $this->getJson(route('informes.cuenta-corriente.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $this->assertEquals(60000.0, round($sumaACobrar, 2));
        $this->assertEquals($saldos['data'][0]['total'], round($sumaACobrar, 2));
    }

    public function test_cliente_sin_saldo_inicial_no_genera_fila_saldo_inicial(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertEquals('venta', $resp['data'][0]['operacion']);
    }

    public function test_filtrar_por_operacion_saldo_inicial_devuelve_solo_esas_filas_de_todos_los_clientes(): void
    {
        $c1 = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        $c2 = Cliente::factory()->create([
            'saldo_inicial' => 20000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(10)->toDateString(),
        ]);
        $c3 = Cliente::factory()->create(['saldo_inicial' => 0]);
        Venta::factory()->create(['cliente_id' => $c3->id, 'total' => 1000]);

        $resp = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'operacion' => 'saldo_inicial',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);
        $clienteIds = collect($resp['data'])->pluck('cliente_id')->sort()->values()->all();
        $this->assertEquals([$c1->id, $c2->id], $clienteIds);
    }
}
