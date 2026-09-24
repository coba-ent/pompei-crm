<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 110 — el tipo `vuelto` no puede quedar invisible en los informes de tesorería.
 *
 * **Por qué existe este archivo**: cuando se agregó el tipo `ingreso`, el flujo de caja seguía
 * filtrando por una lista fija que no lo incluía y dejó $34.570.442,27 invisibles. Nada falla en
 * ese escenario: la plata simplemente no aparece. Estos tests fijan que `vuelto` sí se cuente.
 */
class VueltoTesoreriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function ventaCobradaConVuelto(CuentaTesoreria $cuentaCobro, CuentaTesoreria $cuentaVuelto): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21']],
        ])->assertCreated();

        $venta = Venta::latest('id')->firstOrFail(); // total 1210

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuentaCobro->id,
            'monto' => 1500,
            'vuelto' => 290,
            'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertCreated();
    }

    /** El vuelto es plata que sale: tiene que sumar a los egresos del flujo de caja. */
    public function test_el_vuelto_cuenta_como_egreso_en_el_flujo_de_caja(): void
    {
        $cuentaCobro = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->ventaCobradaConVuelto($cuentaCobro, $cuentaVuelto);

        $flujo = app(Tesoreria::class)->flujo(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(1500.0, $flujo['total_cobros'], 'El ingreso entra por el importe recibido.');
        $this->assertSame(290.0, $flujo['total_pagos'], 'El vuelto tiene que figurar como egreso.');
        $this->assertSame(1210.0, $flujo['resultado'], 'El resultado neto es lo que realmente quedó.');
    }

    /**
     * FR-005 / SC-002: el vuelto no es un gasto.
     *
     * Hoy está garantizado por construcción —el informe de Gastos lee la tabla `gastos`, no
     * `movimientos_tesoreria`—, pero el test lo fija por si alguna vez se reescribe sobre el ledger.
     */
    public function test_el_vuelto_no_aparece_en_el_informe_de_gastos(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->ventaCobradaConVuelto($cuenta, $cuenta);

        $this->assertDatabaseCount('gastos', 0);
        $this->assertDatabaseHas('movimientos_tesoreria', ['tipo' => 'vuelto', 'monto' => -290.00]);
    }

    /** La etiqueta del ledger tiene que existir: sin ella la columna "Operación" sale vacía. */
    public function test_el_ledger_muestra_la_operacion_como_vuelto(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->ventaCobradaConVuelto($cuenta, $cuenta);

        $resp = $this->getJson(route('tesoreria.cuentas.data', $cuenta))->assertOk();

        $this->assertStringContainsString('Vuelto', json_encode($resp->json()));
    }
}
