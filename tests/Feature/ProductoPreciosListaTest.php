<?php

namespace Tests\Feature;

use App\Models\ListaPrecio;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoPreciosListaTest extends TestCase
{
    use RefreshDatabase;

    public function test_persiste_precios_por_lista(): void
    {
        $mayorista = ListaPrecio::create(['nombre' => 'Mayorista']);
        $minorista = ListaPrecio::create(['nombre' => 'Minorista']);

        $this->postJson(route('productos.store'), [
            'nombre' => 'Producto',
            'tipo' => 'producto',
            'precios' => [
                ['lista_precio_id' => $mayorista->id, 'precio' => 800],
                ['lista_precio_id' => $minorista->id, 'precio' => 1200],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('precios_producto', 2);
        $this->assertDatabaseHas('precios_producto', ['lista_precio_id' => $mayorista->id, 'precio' => 800]);
    }

    public function test_edicion_no_duplica_precio_por_lista(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Mayorista']);
        $producto = Producto::create(['nombre' => 'Producto', 'tipo' => 'producto']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 800]);

        $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Producto',
            'tipo' => 'producto',
            'precios' => [
                ['lista_precio_id' => $lista->id, 'precio' => 950],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('precios_producto', 1);
        $this->assertDatabaseHas('precios_producto', ['lista_precio_id' => $lista->id, 'precio' => 950]);
    }

    public function test_rechaza_precio_negativo(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Mayorista']);

        $this->postJson(route('productos.store'), [
            'nombre' => 'Producto',
            'tipo' => 'producto',
            'precios' => [
                ['lista_precio_id' => $lista->id, 'precio' => -5],
            ],
        ])->assertStatus(422);
    }
}
