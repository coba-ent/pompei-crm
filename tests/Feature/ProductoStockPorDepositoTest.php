<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de Productos: además del stock total, una columna por depósito
 * (28/07/2026, a pedido del usuario). Mismo patrón dinámico que las listas de
 * precio — si se da de alta un depósito nuevo, aparece su columna sin tocar
 * código.
 *
 * Motivo: el total sumado esconde de dónde sale el stock. La publicación en
 * Mercado Libre trabaja contra UN depósito, así que ver sólo el total hace
 * parecer que la sincronización falla cuando en realidad está bien
 * (ver MERCADOLIBRE_NOTAS_TECNICAS.md §10).
 */
class ProductoStockPorDepositoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function primeraFila(): array
    {
        return $this->getJson(route('productos.data').'?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');
    }

    public function test_devuelve_una_columna_de_stock_por_cada_deposito(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $secundario = Deposito::create(['nombre' => 'Secundario', 'activo' => true]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $principal->id, 'cantidad' => 7]);
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $secundario->id, 'cantidad' => 10]);

        $fila = $this->primeraFila();

        $this->assertEquals(7, $fila['stock_deposito_'.$principal->id]);
        $this->assertEquals(10, $fila['stock_deposito_'.$secundario->id]);
        // El total sigue siendo la suma de todos — es justamente el número que confunde.
        $this->assertEquals(17, $fila['stock_total']);
    }

    public function test_un_deposito_nuevo_aparece_solo_sin_tocar_codigo(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $principal->id, 'cantidad' => 5]);

        $this->assertArrayNotHasKey('stock_deposito_999', $this->primeraFila());

        $nuevo = Deposito::create(['nombre' => 'Recién creado', 'activo' => true]);

        $fila = $this->primeraFila();
        $this->assertArrayHasKey('stock_deposito_'.$nuevo->id, $fila);
        $this->assertEquals(0, $fila['stock_deposito_'.$nuevo->id], 'Sin stock cargado el depósito nuevo va en 0.');
    }

    public function test_los_depositos_inactivos_no_generan_columna(): void
    {
        $activo = Deposito::create(['nombre' => 'Activo', 'activo' => true]);
        $inactivo = Deposito::create(['nombre' => 'Inactivo', 'activo' => false]);

        Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);

        $fila = $this->primeraFila();

        $this->assertArrayHasKey('stock_deposito_'.$activo->id, $fila);
        $this->assertArrayNotHasKey('stock_deposito_'.$inactivo->id, $fila);
    }

    /** Los Servicios no llevan stock: van en null para mostrarse como "—", no como 0. */
    public function test_los_servicios_no_muestran_stock_por_deposito(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Producto::factory()->create(['tipo' => 'servicio', 'activo' => true]);

        $fila = $this->primeraFila();

        $this->assertNull($fila['stock_deposito_'.$deposito->id]);
        $this->assertNull($fila['stock_total']);
    }

    public function test_el_stock_negativo_se_informa_tal_cual_para_poder_detectarlo(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => -4]);

        $this->assertEquals(-4, $this->primeraFila()['stock_deposito_'.$deposito->id]);
    }

    /** El encabezado de la tabla tiene que traer una columna con el nombre de cada depósito. */
    public function test_la_pantalla_renderiza_una_columna_por_deposito(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Deposito::create(['nombre' => 'Depósito Nuevo', 'activo' => true]);

        $respuesta = $this->get(route('productos.index'))->assertOk();

        $respuesta->assertSee('Unidades en el depósito Principal', false);
        $respuesta->assertSee('Unidades en el depósito Depósito Nuevo', false);
    }
}
