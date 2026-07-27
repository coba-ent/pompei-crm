<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Presupuesto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US2 — Venta + Cobranza: impacto en Tesorería, A Cobrar derivado, conversión, soft delete (SC-002/003/004/005). */
class VentaCobranzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(Cliente $cliente, array $overrides = []): Venta
    {
        $payload = array_merge([
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ], $overrides);

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_cobro_impacta_el_saldo_de_tesoreria_exactamente(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $saldoAntes = $cuenta->saldoA();

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame($saldoAntes + 1210.0, $cuenta->saldoA());
        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());
    }

    public function test_a_cobrar_se_deriva_de_cobros_parciales(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 500,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $venta->refresh();
        $this->assertSame('parcial', $venta->estadoCobro());
        $this->assertSame(710.0, $venta->aCobrar());

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 710,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());
        $this->assertSame(0.0, $venta->fresh()->aCobrar());
    }

    public function test_monto_de_cobro_no_puede_superar_el_saldo_a_cobrar(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_crear_venta_desde_presupuesto_preserva_datos_y_marca_convertido(): void
    {
        $cliente = Cliente::factory()->create();
        $presupuesto = Presupuesto::factory()->create(['cliente_id' => $cliente->id, 'total' => 500]);

        $venta = $this->crearVenta($cliente, ['presupuesto_id' => $presupuesto->id]);

        $this->assertSame($presupuesto->id, $venta->presupuesto_id);
        $this->assertTrue($presupuesto->fresh()->convertido());
        $this->assertSame($venta->id, $presupuesto->fresh()->venta_id);
    }

    public function test_soft_delete_de_venta_revierte_el_movimiento_de_tesoreria(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $saldoConCobro = $cuenta->saldoA();
        $this->assertSame(1210.0, $saldoConCobro);

        $this->deleteJson(route('ventas.destroy', $venta))->assertOk();

        $this->assertSame(0.0, $cuenta->saldoA());
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
    }
}
