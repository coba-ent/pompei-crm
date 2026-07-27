<?php

namespace Tests\Feature;

use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_producto_con_nombre_y_precio(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Remera básica',
            'tipo' => 'producto',
            'precio_venta' => 1500.50,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('productos', [
            'nombre' => 'Remera básica',
            'tipo' => 'producto',
            'activo' => true,
        ]);
    }

    public function test_rechaza_alta_sin_nombre(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => '',
            'tipo' => 'producto',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['errors' => ['nombre']]);

        $this->assertSame(0, Producto::count());
    }

    public function test_crea_servicio_sin_control_de_stock(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Flete',
            'tipo' => 'servicio',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $producto = Producto::first();
        $this->assertTrue($producto->esServicio());
        $this->assertFalse($producto->controlaStock());
    }

    public function test_edita_producto(): void
    {
        $producto = Producto::create(['nombre' => 'Original', 'tipo' => 'producto']);

        $response = $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Modificado',
            'tipo' => 'producto',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'nombre' => 'Modificado']);
    }

    // ---- US2: económicos + flags ----

    public function test_rechaza_importes_o_iva_negativos(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Precio malo',
            'tipo' => 'producto',
            'precio_venta' => -10,
            'iva_venta_pct' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['precio_venta', 'iva_venta_pct']]);
    }

    public function test_persiste_economicos_y_flags(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Con datos',
            'tipo' => 'producto',
            'precio_venta' => 1000.25,
            'iva_venta_pct' => 21,
            'costo' => 500.10,
            'iva_compra_pct' => 10.5,
            'mostrar_en_ventas' => 1,
            'mostrar_en_compras' => 0,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('productos', [
            'nombre' => 'Con datos',
            'precio_venta' => 1000.25,
            'costo' => 500.10,
            'iva_venta_pct' => '21',
            'iva_compra_pct' => '10.5',
            'mostrar_en_ventas' => true,
            'mostrar_en_compras' => false,
        ]);
    }

    public function test_acepta_iva_exento_y_no_gravado(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Exento',
            'tipo' => 'servicio',
            'iva_venta_pct' => 'exento',
            'iva_compra_pct' => 'no_gravado',
        ]);

        $response->assertOk();
        $producto = \App\Models\Producto::where('nombre', 'Exento')->firstOrFail();
        $this->assertSame('exento', $producto->iva_venta_pct);
        $this->assertSame(0.0, $producto->ivaVentaPorcentaje());
        $this->assertSame(0.0, $producto->ivaCompraPorcentaje());
    }

    public function test_rechaza_iva_fuera_de_las_opciones(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Iva raro',
            'tipo' => 'producto',
            'iva_venta_pct' => '18',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['iva_venta_pct']]);
    }

    public function test_sube_y_elimina_imagen(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Con imagen',
            'tipo' => 'producto',
            'imagen' => \Illuminate\Http\Testing\File::image('foto.jpg', 100, 100),
        ]);

        $response->assertOk();
        $producto = \App\Models\Producto::where('nombre', 'Con imagen')->firstOrFail();
        $this->assertNotNull($producto->imagen);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($producto->imagen);

        // Ahora la elimina.
        $ruta = $producto->imagen;
        $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Con imagen',
            'tipo' => 'producto',
            'imagen_eliminar' => 1,
        ])->assertOk();

        $this->assertNull($producto->fresh()->imagen);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($ruta);
    }

    public function test_persiste_proveedor_y_estado_inactivo(): void
    {
        $proveedor = \App\Models\Proveedor::factory()->create();

        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Con proveedor',
            'tipo' => 'producto',
            'proveedor_id' => $proveedor->id,
            'activo' => 0,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('productos', [
            'nombre' => 'Con proveedor',
            'proveedor_id' => $proveedor->id,
            'activo' => false,
        ]);
    }
}
