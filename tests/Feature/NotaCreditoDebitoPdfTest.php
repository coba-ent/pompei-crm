<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 057 US3: PDF de NC/ND generalizado a Compras (antes sólo Ventas). */
class NotaCreditoDebitoPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    /** T030 */
    public function test_pdf_de_nota_de_compra_devuelve_200(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = \App\Models\Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();
        $compra = Compra::firstOrFail();

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Devolución',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();
        $nota = $compra->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->get(route('compras.notas.pdf', $nota));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /** T031: no regresión en Ventas. */
    public function test_pdf_de_nota_de_venta_sigue_funcionando(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Devolución',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();
        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->get(route('ventas.notas.pdf', $nota));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
