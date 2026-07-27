<?php

namespace Tests\Feature;

use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoVarianteTest extends TestCase
{
    use RefreshDatabase;

    public function test_persiste_variantes_con_sku_distinto(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Remera',
            'tipo' => 'producto',
            'variantes' => [
                ['talle' => 'S', 'sku' => 'REM-S'],
                ['talle' => 'M', 'sku' => 'REM-M'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('producto_variantes', 2);
    }

    public function test_rechaza_sku_de_variante_duplicado_en_payload(): void
    {
        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Remera',
            'tipo' => 'producto',
            'variantes' => [
                ['talle' => 'S', 'sku' => 'REP'],
                ['talle' => 'M', 'sku' => 'REP'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('producto_variantes', 0);
    }

    public function test_rechaza_sku_variante_contra_producto_existente(): void
    {
        Producto::create(['nombre' => 'Otro', 'tipo' => 'producto', 'codigo' => 'COD-1']);

        $response = $this->postJson(route('productos.store'), [
            'nombre' => 'Remera',
            'tipo' => 'producto',
            'variantes' => [
                ['talle' => 'S', 'sku' => 'COD-1'],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_quitar_variante_sin_operaciones_la_elimina(): void
    {
        $producto = Producto::create(['nombre' => 'Remera', 'tipo' => 'producto']);
        $producto->variantes()->create(['sku' => 'REM-S', 'talle' => 'S']);

        // Update sin esa variante en el payload.
        $this->patchJson(route('productos.update', $producto), [
            'nombre' => 'Remera',
            'tipo' => 'producto',
            'variantes' => [],
        ])->assertOk();

        $this->assertDatabaseCount('producto_variantes', 0);
    }
}
