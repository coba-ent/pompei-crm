<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\MovimientoTesoreria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El test que más importa (spec 064, FR-010, FR-011, SC-003): crear, editar y eliminar un remito
 * —en Ventas y en Compras— NO debe generar movimientos de stock, de tesorería, ni alterar el total
 * de la operación de origen. Mismo criterio que la spec 063.
 */
class RemitoNoMueveNadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(Producto $producto): Venta
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 3, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    private function crearCompra(Producto $producto): Compra
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
                ['producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 3, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Compra::firstOrFail();
    }

    public function test_crear_remito_de_venta_no_mueve_stock_ni_tesoreria_ni_total(): void
    {
        $producto = Producto::factory()->create();
        $venta = $this->crearVenta($producto);
        $item = $venta->items->first();

        $stockAntes = $producto->fresh()->stockTotal();
        $totalAntes = $venta->fresh()->total;
        $movStockAntes = MovimientoStock::count();
        $movTesoreriaAntes = MovimientoTesoreria::count();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();

        $this->assertSame($stockAntes, $producto->fresh()->stockTotal());
        $this->assertSame((float) $totalAntes, (float) $venta->fresh()->total);
        $this->assertSame($movStockAntes, MovimientoStock::count());
        $this->assertSame($movTesoreriaAntes, MovimientoTesoreria::count());
        $this->assertNull($venta->fresh()->comprobanteFiscal);
    }

    public function test_editar_remito_de_venta_no_mueve_stock_ni_tesoreria(): void
    {
        $producto = Producto::factory()->create();
        $venta = $this->crearVenta($producto);
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();
        $remito = $venta->fresh()->remitos()->firstOrFail();

        $stockAntes = $producto->fresh()->stockTotal();
        $movStockAntes = MovimientoStock::count();
        $movTesoreriaAntes = MovimientoTesoreria::count();

        $this->putJson(route('ventas.remitos.update', [$venta, $remito]), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 1],
            ],
        ])->assertOk();

        $this->assertSame($stockAntes, $producto->fresh()->stockTotal());
        $this->assertSame($movStockAntes, MovimientoStock::count());
        $this->assertSame($movTesoreriaAntes, MovimientoTesoreria::count());
        $this->assertSame((float) $venta->fresh()->total, (float) $venta->fresh()->total);
    }

    public function test_eliminar_remito_de_venta_no_mueve_stock_ni_tesoreria(): void
    {
        $producto = Producto::factory()->create();
        $venta = $this->crearVenta($producto);
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();
        $remito = $venta->fresh()->remitos()->firstOrFail();

        $stockAntes = $producto->fresh()->stockTotal();
        $movStockAntes = MovimientoStock::count();
        $movTesoreriaAntes = MovimientoTesoreria::count();

        $this->deleteJson(route('ventas.remitos.destroy', [$venta, $remito]))->assertOk();

        $this->assertSame($stockAntes, $producto->fresh()->stockTotal());
        $this->assertSame($movStockAntes, MovimientoStock::count());
        $this->assertSame($movTesoreriaAntes, MovimientoTesoreria::count());
        $this->assertModelMissing($remito);
    }

    public function test_crear_remito_de_compra_no_mueve_stock_ni_tesoreria(): void
    {
        $producto = Producto::factory()->create();
        $compra = $this->crearCompra($producto);
        $item = $compra->items->first();

        $stockAntes = $producto->fresh()->stockTotal();
        $movStockAntes = MovimientoStock::count();
        $movTesoreriaAntes = MovimientoTesoreria::count();

        $this->postJson(route('compras.remitos.store', $compra), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();

        $this->assertSame($stockAntes, $producto->fresh()->stockTotal());
        $this->assertSame($movStockAntes, MovimientoStock::count());
        $this->assertSame($movTesoreriaAntes, MovimientoTesoreria::count());
    }

    public function test_eliminar_la_venta_elimina_sus_remitos(): void
    {
        $producto = Producto::factory()->create();
        $venta = $this->crearVenta($producto);
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();
        $remito = $venta->fresh()->remitos()->firstOrFail();

        $this->deleteJson(route('ventas.destroy', $venta))->assertOk();

        $this->assertModelMissing($remito);
    }
}
