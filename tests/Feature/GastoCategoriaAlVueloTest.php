<?php

namespace Tests\Feature;

use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoCategoriaAlVueloTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_categoria_de_gasto_al_vuelo(): void
    {
        $response = $this->postJson(route('gastos.categorias.store'), [
            'nombre' => 'Impuestos',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('categorias', [
            'nombre' => 'Impuestos',
            'tipo' => 'gasto',
            'categoria_padre_id' => null,
        ]);
    }

    public function test_crea_subcategoria_de_gasto_hija_de_una_categoria(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Impuestos']);

        $response = $this->postJson(route('gastos.subcategorias.store'), [
            'nombre' => 'IIBB',
            'categoria_id' => $categoria->id,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('categorias', [
            'nombre' => 'IIBB',
            'tipo' => 'gasto',
            'categoria_padre_id' => $categoria->id,
        ]);
    }
}
