<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\OtroIngreso;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuentaCorrienteInformeTest extends TestCase
{
    use RefreshDatabase;

    public function test_listado_muestra_el_saldo_correcto(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 40000]);

        $resp = $this->getJson(route('cuentas-corrientes.clientes.data'))->assertOk()->json();

        $fila = collect($resp['data'])->firstWhere('id', $cliente->id);
        $this->assertNotNull($fila);
        $this->assertEquals(60000.0, $fila['saldo']);
        $this->assertFalse($fila['a_favor']);
    }

    /**
     * Invariante SC-002: el saldo_final del detalle == último acumulado ==
     * saldo del listado, incluso paginado en varias páginas.
     */
    public function test_acumulado_del_detalle_coincide_con_el_saldo_del_listado_paginado(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 10000]);

        // 25 ventas de $1000 (varias páginas con length=10) para forzar acumulado de apertura.
        for ($i = 0; $i < 25; $i++) {
            Venta::factory()->create([
                'cliente_id' => $cliente->id,
                'total' => 1000,
                'fecha_emision' => now()->subDays(25 - $i)->toDateString(),
            ]);
        }

        $saldoListado = $cliente->fresh()->saldoCuentaCorriente();
        $this->assertSame(35000.0, $saldoListado);

        // Página 3 (start=20, length=10) — cubre el acumulado de apertura de 21 filas previas.
        $resp = $this->getJson(route('cuentas-corrientes.clientes.movimientos.data', $cliente).'?start=20&length=10&draw=1')
            ->assertOk()->json();

        $this->assertEquals($saldoListado, $resp['saldo_final']);
        $ultimaFila = end($resp['data']);
        $this->assertEquals($saldoListado, $ultimaFila['acumulado']);
    }

    public function test_saldo_inicial_como_apertura_sin_operaciones(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 25000]);

        $resp = $this->getJson(route('cuentas-corrientes.clientes.movimientos.data', $cliente))->assertOk()->json();

        $this->assertEquals(25000.0, $resp['saldo_final']);
        $this->assertSame('saldo_inicial', $resp['data'][0]['tipo']);
    }

    public function test_gastos_y_otros_ingresos_no_alteran_ningun_saldo(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $categoria = Categoria::factory()->create();
        $cuenta = CuentaTesoreria::factory()->create();

        Gasto::factory()->create();
        OtroIngreso::create([
            'categoria_id' => $categoria->id,
            'cliente_id' => $cliente->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => now()->toDateString(),
            'monto' => 99999,
            'estado' => 'registrado',
        ]);

        $this->assertSame(0.0, $cliente->fresh()->saldoCuentaCorriente());
    }
}
