<?php

namespace Tests\Feature;

use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoSkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_rechaza_codigo_duplicado(): void
    {
        Producto::create(['nombre' => 'Uno', 'tipo' => 'producto', 'codigo' => 'ABC']);

        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Dos',
            'tipo' => 'producto',
            'codigo' => 'ABC',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['codigo']]);
    }

    public function test_permite_varios_sin_codigo(): void
    {
        foreach (['A', 'B', 'C'] as $nombre) {
            $this->postJson(route('productos.store'), ['nombre' => $nombre, 'tipo' => 'producto'])
                ->assertOk();
        }

        $this->assertSame(3, Producto::count());
    }

    public function test_update_sin_cambiar_codigo_no_falla(): void
    {
        $producto = Producto::create(['nombre' => 'Uno', 'tipo' => 'producto', 'codigo' => 'XYZ']);

        $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Uno editado',
            'tipo' => 'producto',
            'codigo' => 'XYZ',
        ])->assertOk();
    }
}
