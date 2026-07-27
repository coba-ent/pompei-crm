<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US4 — NC/ND sobre una Compra: afecta la barra de ecuación (aPagar), constraint exactamente uno de venta_id/compra_id. */
class NotaCreditoDebitoCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearCompra(): Compra
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Compra::firstOrFail();
    }

    public function test_nota_de_credito_sobre_compra_resta_del_a_pagar(): void
    {
        $compra = $this->crearCompra(); // total = 1210

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'fecha_emision' => now()->toDateString(),
            'monto' => 210,
            'descripcion' => 'Devolución parcial',
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame(1000.0, $compra->fresh()->aPagar());
    }

    public function test_nota_de_debito_sobre_compra_suma_al_a_pagar(): void
    {
        $compra = $this->crearCompra(); // total = 1210

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'debito',
            'afecta_stock' => false,
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'descripcion' => 'Interés por mora',
        ])->assertCreated();

        $this->assertSame(1310.0, $compra->fresh()->aPagar());
    }

    public function test_nota_creada_desde_compra_tiene_compra_id_y_no_venta_id(): void
    {
        $compra = $this->crearCompra();

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'descripcion' => 'Ajuste',
        ])->assertCreated();

        $nota = $compra->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame($compra->id, $nota->compra_id);
        $this->assertNull($nota->venta_id);
    }
}
