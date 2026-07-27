<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoBajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_alterna_activo(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto', 'activo' => true]);

        $this->patchJson(route('productos.estado', $producto))
            ->assertOk()->assertJsonPath('activo', false);

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'activo' => false]);
    }

    public function test_destroy_elimina_sin_operaciones(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);

        $this->deleteJson(route('productos.destroy', $producto))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('productos', ['id' => $producto->id]);
    }

    public function test_destroy_rechaza_con_movimientos_de_stock(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);
        $deposito = Deposito::create(['nombre' => 'Principal']);

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 5,
        ])->assertOk();

        $this->deleteJson(route('productos.destroy', $producto))
            ->assertStatus(409)->assertJson(['ok' => false]);

        $this->assertDatabaseHas('productos', ['id' => $producto->id]);
    }
}
