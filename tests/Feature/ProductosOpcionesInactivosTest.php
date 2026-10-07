<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El buscador de productos de los informes (Stock, Ventas, Compras) tiene que ofrecer los
 * inactivos: un producto dado de baja conserva sus movimientos y el cliente los consulta
 * (caso real: el 40980, inactivado con 9 movimientos de stock). Los buscadores de carga de
 * Venta/Compra/Presupuesto siguen sin ofrecerlos.
 */
class ProductosOpcionesInactivosTest extends TestCase
{
    use RefreshDatabase;

    public function test_por_defecto_no_ofrece_inactivos_y_con_el_flag_si(): void
    {
        Producto::factory()->create(['nombre' => 'Tapa inodoro activa', 'activo' => true]);
        $inactivo = Producto::factory()->create(['nombre' => 'Tapa inodoro dada de baja', 'activo' => false]);

        $this->actingAs(User::factory()->create());

        $carga = $this->getJson(route('productos.opciones', ['q' => 'tapa']))->assertOk()->json('data');
        $this->assertNotContains($inactivo->id, array_column($carga, 'id'));

        $informe = $this->getJson(route('productos.opciones', ['q' => 'tapa', 'incluir_inactivos' => 1]))->assertOk()->json('data');
        $fila = collect($informe)->firstWhere('id', $inactivo->id);
        $this->assertNotNull($fila, 'Con incluir_inactivos el informe tiene que poder elegir el producto inactivo.');
        $this->assertFalse($fila['activo']);
        $this->assertCount(2, $informe);
    }
}
