<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 — cálculo de totales de Compra: IVA opcional (SC-001), idempotencia del guardado (SC-007). */
class CompraCalculoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function payload(Proveedor $proveedor, array $overrides = []): array
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        return array_merge([
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => null],
            ],
        ], $overrides);
    }

    public function test_item_sin_iva_pct_no_gravado_no_aplica_iva(): void
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), $this->payload($proveedor))->assertCreated();

        $compra = Compra::firstOrFail();
        $this->assertSame(1000.0, (float) $compra->total);
        $this->assertNull($compra->items->first()->iva_pct);
    }

    public function test_item_con_iva_elegido_aplica_el_porcentaje(): void
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), $this->payload($proveedor, [
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ]))->assertCreated();

        $compra = Compra::firstOrFail();
        $this->assertSame(1210.0, (float) $compra->total);
    }

    public function test_descuento_y_percepciones_impactan_el_total(): void
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), $this->payload($proveedor, [
            'descuento_general_pct' => 10,
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 2, 'precio_unitario' => 500, 'iva_pct' => null],
            ],
            'conceptos' => [
                ['tipo' => 'percepcion', 'concepto' => 'IIBB', 'monto' => 50],
            ],
        ]))->assertCreated();

        $compra = Compra::firstOrFail();
        // subtotal 1000, descuento 10% = 100, subtotal_con_descuento 900, + percepcion 50 = 950
        $this->assertSame(100.0, (float) $compra->descuento);
        $this->assertSame(950.0, (float) $compra->total);
    }

    public function test_doble_submit_no_duplica_la_compra(): void
    {
        $proveedor = Proveedor::factory()->create();
        $payload = $this->payload($proveedor);

        $this->postJson(route('compras.store'), $payload)->assertCreated();
        $this->postJson(route('compras.store'), $payload)->assertCreated();

        $this->assertSame(1, Compra::count());
    }
}
