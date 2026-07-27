<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\TipoProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipoProductoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_renombra_y_elimina_tipo(): void
    {
        $this->postJson(route('tipos-producto.store'), ['nombre' => 'Repuestos'])
            ->assertOk()->assertJsonPath('ok', true);

        $tipo = TipoProducto::where('nombre', 'Repuestos')->firstOrFail();

        $this->patchJson(route('tipos-producto.update', $tipo), ['nombre' => 'Repuestos y Accesorios'])->assertOk();
        $this->assertSame('Repuestos y Accesorios', $tipo->fresh()->nombre);

        $this->deleteJson(route('tipos-producto.destroy', $tipo))->assertOk();
        $this->assertDatabaseMissing('tipos_producto', ['id' => $tipo->id]);
    }

    public function test_rechaza_nombre_duplicado(): void
    {
        TipoProducto::create(['nombre' => 'Insumo', 'activo' => true]);
        $this->postJson(route('tipos-producto.store'), ['nombre' => 'Insumo'])->assertStatus(422);
    }

    public function test_producto_persiste_tipo_producto_y_lo_libera_al_borrarlo(): void
    {
        $tipo = TipoProducto::create(['nombre' => 'Fabricado', 'activo' => true]);

        $this->postJson(route('productos.store'), [
            'nombre' => 'Silla',
            'tipo' => 'producto',
            'tipo_producto_id' => $tipo->id,
        ])->assertOk();

        $producto = Producto::where('nombre', 'Silla')->firstOrFail();
        $this->assertSame($tipo->id, $producto->tipo_producto_id);

        // Al eliminar el tipo, el producto queda sin tipo (nullOnDelete).
        $tipo->delete();
        $this->assertNull($producto->fresh()->tipo_producto_id);
    }
}
