<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 — Compra + Pago: impacto en Tesorería, A Pagar derivado, soft delete (SC-002/003/004). */
class CompraPagoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearCompra(Proveedor $proveedor, array $overrides = []): Compra
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = array_merge([
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ], $overrides);

        $this->postJson(route('compras.store'), $payload)->assertCreated();

        return Compra::firstOrFail();
    }

    public function test_pago_impacta_el_saldo_de_tesoreria_exactamente_en_negativo(): void
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $compra = $this->crearCompra($proveedor); // total = 1210

        $saldoAntes = $cuenta->saldoA();

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame($saldoAntes - 1210.0, $cuenta->saldoA());
        $this->assertSame('pagado', $compra->fresh()->estadoPago());
    }

    public function test_a_pagar_se_deriva_de_pagos_parciales(): void
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $compra = $this->crearCompra($proveedor); // total = 1210

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 500,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $compra->refresh();
        $this->assertSame('parcial', $compra->estadoPago());
        $this->assertSame(710.0, $compra->aPagar());

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 710,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('pagado', $compra->fresh()->estadoPago());
        $this->assertSame(0.0, $compra->fresh()->aPagar());
    }

    public function test_monto_de_pago_no_puede_superar_el_saldo_a_pagar(): void
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $compra = $this->crearCompra($proveedor); // total = 1210

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_estado_de_pago_nunca_es_forzable_siempre_se_deriva(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = $this->crearCompra($proveedor);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('compras', 'estado'));
        $this->assertSame('a_pagar', $compra->estadoPago());
    }

    public function test_soft_delete_de_compra_pagada_revierte_el_movimiento_de_tesoreria(): void
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $compra = $this->crearCompra($proveedor);

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame(-1210.0, $cuenta->saldoA());

        $this->deleteJson(route('compras.destroy', $compra))->assertOk();

        $this->assertSame(0.0, $cuenta->saldoA());
        $this->assertSoftDeleted('compras', ['id' => $compra->id]);
    }
}
