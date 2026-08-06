<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
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
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
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
            'mes_imputacion' => now()->toDateString(),
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
            'mes_imputacion' => now()->toDateString(),
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
            'mes_imputacion' => now()->toDateString(),
            'descripcion' => 'Ajuste',
        ])->assertCreated();

        $nota = $compra->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame($compra->id, $nota->compra_id);
        $this->assertNull($nota->venta_id);
    }

    private function crearCompraConProducto(\App\Models\Producto $producto, float $cantidad): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => $cantidad, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Compra::firstOrFail();
    }

    /** T022 (US2): NC sobre Compra con afecta_stock=true sube el stock (signo inverso a Venta). */
    public function test_nota_de_credito_sobre_compra_que_afecta_stock_sube_stock(): void
    {
        $deposito = \App\Models\Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = \App\Models\Producto::factory()->create();
        $compra = $this->crearCompraConProducto($producto, 5);

        $stockAntes = $producto->stockTotal();

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 1000]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 2000,
        ])->assertCreated();

        $this->assertSame($stockAntes - 2.0, $producto->fresh()->stockTotal());
    }

    /** T026 (US2): afecta_stock=true sin deposito_id falla 422 (Compra). */
    public function test_afecta_stock_sin_deposito_falla_validacion_en_compra(): void
    {
        \App\Models\Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = \App\Models\Producto::factory()->create();
        $compra = $this->crearCompraConProducto($producto, 5);

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 1000]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors('deposito_id');
    }
}
