<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAjusteTest extends TestCase
{
    use RefreshDatabase;

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal']);
    }

    public function test_aumento_actualiza_foto_y_registra_movimiento(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);
        $deposito = $this->deposito();

        $response = $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id,
            'operacion' => 'aumento',
            'cantidad' => 10,
        ]);

        $response->assertOk()->assertJsonPath('stock_actual', 10);
        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => 10,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id, 'tipo' => 'ajuste', 'cantidad' => 10,
        ]);
    }

    public function test_disminucion_resta_del_stock(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);
        $deposito = $this->deposito();

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 10,
        ])->assertOk();

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id, 'operacion' => 'disminucion', 'cantidad' => 3,
        ])->assertOk()->assertJsonPath('stock_actual', 7);

        $this->assertDatabaseCount('movimientos_stock', 2);
    }

    public function test_servicio_no_admite_ajuste(): void
    {
        $servicio = Producto::create(['nombre' => 'Flete', 'tipo' => 'servicio']);
        $deposito = $this->deposito();

        $this->postJson(route('productos.stock.ajuste', $servicio), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 5,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['producto']]);

        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_variante_requerida_cuando_el_producto_tiene_variantes(): void
    {
        $producto = Producto::create(['nombre' => 'Remera', 'tipo' => 'producto']);
        $producto->variantes()->create(['sku' => 'REM-S', 'talle' => 'S']);
        $deposito = $this->deposito();

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 5,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['variante_id']]);
    }

    public function test_transferencia_mueve_stock_entre_depositos(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);
        $salida = Deposito::create(['nombre' => 'Central']);
        $entrada = Deposito::create(['nombre' => 'Sucursal']);

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $salida->id, 'operacion' => 'aumento', 'cantidad' => 10,
        ])->assertOk();

        $this->postJson(route('productos.stock.transferencia', $producto), [
            'deposito_salida_id' => $salida->id,
            'deposito_entrada_id' => $entrada->id,
            'cantidad' => 4,
        ])->assertOk()
            ->assertJsonPath('stock_salida', 6)
            ->assertJsonPath('stock_entrada', 4);

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $salida->id, 'cantidad' => 6]);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $entrada->id, 'cantidad' => 4]);
        $this->assertDatabaseCount('movimientos_stock', 3); // 1 aumento + 2 transferencia
    }

    public function test_transferencia_rechaza_mismo_deposito(): void
    {
        $producto = Producto::create(['nombre' => 'P', 'tipo' => 'producto']);
        $deposito = $this->deposito();

        $this->postJson(route('productos.stock.transferencia', $producto), [
            'deposito_salida_id' => $deposito->id,
            'deposito_entrada_id' => $deposito->id,
            'cantidad' => 4,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['deposito_entrada_id']]);
    }
}
