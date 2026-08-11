<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 058: ítems de Compra con cantidad negativa (bonificaciones/devoluciones
 * del proveedor cargadas dentro de la misma Compra) — precio unitario sigue positivo.
 */
class CompraItemNegativoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function payloadBase(array $items): array
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        return [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-07-27',
            'items' => $items,
        ];
    }

    public function test_crear_compra_con_item_cantidad_negativa_y_precio_positivo_es_aceptado(): void
    {
        $respuesta = $this->postJson(route('compras.store'), $this->payloadBase([[
            'descripcion' => 'Bonificación proveedor',
            'cantidad' => -2,
            'precio_unitario' => 50,
        ]]));

        $respuesta->assertCreated();
    }

    public function test_crear_compra_con_item_cantidad_cero_sigue_rechazado(): void
    {
        $respuesta = $this->postJson(route('compras.store'), $this->payloadBase([[
            'descripcion' => 'Item inválido',
            'cantidad' => 0,
            'precio_unitario' => 50,
        ]]));

        $respuesta->assertStatus(422);
    }

    public function test_crear_compra_con_precio_unitario_negativo_es_rechazado(): void
    {
        $respuesta = $this->postJson(route('compras.store'), $this->payloadBase([[
            'descripcion' => 'Item inválido',
            'cantidad' => 2,
            'precio_unitario' => -50,
        ]]));

        $respuesta->assertStatus(422);
    }

    public function test_items_positivo_y_negativo_del_mismo_producto_dejan_stock_neto_correcto(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $respuesta = $this->postJson(route('compras.store'), $this->payloadBase([
            [
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => 3,
                'precio_unitario' => 100,
            ],
            [
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre.' (devolución)',
                'cantidad' => -1,
                'precio_unitario' => 100,
            ],
        ]));

        $respuesta->assertCreated();

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => 2,
        ]);
    }

    public function test_editar_compra_de_cantidad_positiva_a_negativa_recalcula_stock_correctamente(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $respuestaAlta = $this->postJson(route('compras.store'), $this->payloadBase([[
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => 5,
            'precio_unitario' => 100,
        ]]));
        $respuestaAlta->assertCreated();
        $compra = Compra::findOrFail($respuestaAlta->json('compra.id'));

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'cantidad' => 5]);

        $respuestaEdicion = $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $compra->proveedor_id,
            'deposito_id' => $compra->deposito_id,
            'nro_comprobante' => $compra->nro_comprobante,
            'fecha_emision' => '2026-07-27',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => -2,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuestaEdicion->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'cantidad' => -2]);
    }
}
