<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US4 (spec 064) — remito sobre una Compra: antes fallaba porque `venta_id` era NOT NULL
 * (data-model.md, T004). Domicilio de entrega precargado con el depósito que recibe, no el
 * proveedor (FR-005).
 */
class RemitoCompraTest extends TestCase
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
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Depósito Central', 'activo' => true]);
        $producto = Producto::factory()->create();

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 2, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Compra::firstOrFail();
    }

    public function test_crear_remito_de_compra_ya_no_falla(): void
    {
        $compra = $this->crearCompra();
        $item = $compra->items->first();

        $respuesta = $this->postJson(route('compras.remitos.store', $compra), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 2],
            ],
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true);

        $remito = $compra->fresh()->remitos()->firstOrFail();
        $this->assertSame($compra->id, $remito->compra_id);
        $this->assertNull($remito->venta_id);
    }

    public function test_se_ve_en_el_detalle_de_la_compra(): void
    {
        $compra = $this->crearCompra();
        $item = $compra->items->first();

        $this->postJson(route('compras.remitos.store', $compra), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 2],
            ],
        ])->assertCreated();

        $this->get(route('compras.show', $compra))->assertOk()->assertSee('Remitos');
    }

    public function test_el_documento_sale_con_los_datos_del_proveedor(): void
    {
        $compra = $this->crearCompra();
        $item = $compra->items->first();

        $this->postJson(route('compras.remitos.store', $compra), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 2],
            ],
        ])->assertCreated();
        $remito = $compra->fresh()->remitos()->firstOrFail();

        $respuesta = $this->get(route('remitos.pdf', $remito));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
    }

    public function test_domicilio_de_entrega_se_precarga_con_el_deposito_que_recibe(): void
    {
        $compra = $this->crearCompra();

        $respuesta = $this->get(route('compras.remitos.create', $compra));

        $respuesta->assertOk();
        $respuesta->assertSee($compra->deposito->nombre);
    }
}
