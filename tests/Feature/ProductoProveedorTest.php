<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US2: asociar un Proveedor a un Producto — persistencia y filtro del listado. */
class ProductoProveedorTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_producto_con_proveedor_y_lo_persiste(): void
    {
        $proveedor = Proveedor::factory()->create();

        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Con proveedor',
            'tipo' => 'producto',
            'proveedor_id' => $proveedor->id,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('productos', ['nombre' => 'Con proveedor', 'proveedor_id' => $proveedor->id]);
    }

    public function test_edita_producto_asignandole_proveedor(): void
    {
        $producto = Producto::create(['nombre' => 'Sin proveedor', 'tipo' => 'producto']);
        $proveedor = Proveedor::factory()->create();

        $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Sin proveedor',
            'tipo' => 'producto',
            'proveedor_id' => $proveedor->id,
        ])->assertOk();

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'proveedor_id' => $proveedor->id]);
    }

    public function test_filtro_proveedor_id_del_listado_devuelve_solo_sus_productos(): void
    {
        $proveedorA = Proveedor::factory()->create();
        $proveedorB = Proveedor::factory()->create();

        Producto::create(['nombre' => 'De A', 'tipo' => 'producto', 'proveedor_id' => $proveedorA->id]);
        Producto::create(['nombre' => 'De B', 'tipo' => 'producto', 'proveedor_id' => $proveedorB->id]);

        $resp = $this->getJson(route('productos.data')."?draw=1&start=0&length=10&proveedor_id={$proveedorA->id}")
            ->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertSame('De A', $resp['data'][0]['nombre']);
        $this->assertSame($proveedorA->nombre, $resp['data'][0]['proveedor']);
    }
}
