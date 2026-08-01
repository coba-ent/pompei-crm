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
 * Cierre de la brecha de stock de Compras (spec 030, research.md §R1): antes
 * de esta spec las Compras del CRM no generaban ningún movimiento de stock.
 * Análogo a tests/Feature/Ingresos/VentaStockTest.php pero en sentido de
 * entrada.
 */
class CompraStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearCompraConProducto(Producto $producto, float $cantidad = 10): Compra
    {
        $proveedor = Proveedor::factory()->create();

        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-27',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertCreated();

        return Compra::findOrFail($respuesta->json('compra.id'));
    }

    public function test_alta_de_compra_suma_stock_de_items_que_controlan_stock(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompraConProducto($producto, 10);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => 10,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'tipo' => 'entrada',
            'cantidad' => 10,
            'origen_type' => Compra::class,
            'origen_id' => $compra->id,
        ]);
        $this->assertSame('2026-07-27', \App\Models\MovimientoStock::first()->fecha->toDateString());
    }

    public function test_alta_de_compra_no_mueve_stock_de_items_que_no_controlan_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $servicio = Producto::factory()->create(['tipo' => 'servicio']);

        $this->crearCompraConProducto($servicio, 2);

        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_edicion_de_compra_reintegra_cantidad_anterior_y_aplica_la_nueva(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompraConProducto($producto, 10);

        $respuesta = $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $compra->proveedor_id,
            'fecha_emision' => '2026-07-27',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => 6,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertOk();

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => 6,
        ]);
    }

    public function test_edicion_de_compra_reemplaza_producto_del_item(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $productoA = Producto::factory()->create(['tipo' => 'producto']);
        $productoB = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompraConProducto($productoA, 5);

        $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $compra->proveedor_id,
            'fecha_emision' => '2026-07-27',
            'items' => [[
                'producto_id' => $productoB->id,
                'descripcion' => $productoB->nombre,
                'cantidad' => 5,
                'precio_unitario' => 100,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $productoA->id, 'cantidad' => 0]);
        $this->assertDatabaseHas('stocks', ['producto_id' => $productoB->id, 'cantidad' => 5]);
    }

    public function test_baja_de_compra_reintegra_stock_sumado(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompraConProducto($producto, 10);

        $this->deleteJson(route('compras.destroy', $compra))->assertOk();

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => 0,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'tipo' => 'salida',
            'cantidad' => -10,
            'origen_type' => Compra::class,
            'origen_id' => $compra->id,
        ]);
    }

    public function test_alta_de_compra_es_atomica_con_movimientos_de_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $proveedor = Proveedor::factory()->create();

        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-27',
            'items' => [
                [
                    'producto_id' => $producto->id,
                    'descripcion' => $producto->nombre,
                    'cantidad' => 10,
                    'precio_unitario' => 100,
                ],
                [
                    // cantidad inválida (<= 0): dispara 422 en la validación, antes de tocar la BD.
                    'producto_id' => $producto->id,
                    'descripcion' => $producto->nombre,
                    'cantidad' => 0,
                    'precio_unitario' => 100,
                ],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertDatabaseCount('compras', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
    }
}
