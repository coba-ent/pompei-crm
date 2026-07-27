<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US3 — Informe de Stock: cálculo de "Stock Saldo" (research.md §2), KPIs de
 * valorización (misma fórmula que ProductoController::estadisticas()) y el
 * límite del filtro "Operación" a los tipos que el sistema genera hoy (FR-013).
 */
class InformeStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_saldo_no_cambia_al_aplicar_filtros_que_excluyen_movimientos_anteriores(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 10, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 5, 'fecha' => '2026-06-10',
        ]);

        // Sin filtros: la fila del ajuste (06-10) acumula el saldo corrido completo (10 + 5 = 15).
        $sinFiltro = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json();
        $filaSinFiltro = collect($sinFiltro['data'])->firstWhere('tipo', 'ajuste');
        $this->assertEquals(15, $filaSinFiltro['stock_saldo']);

        // Con un fecha_desde que excluye el movimiento de "entrada" (06-01) de la
        // tabla mostrada, el saldo de la fila de ajuste que sí se muestra NO cambia:
        // el cálculo se hace sobre el histórico completo, el filtro sólo oculta filas.
        $conFiltro = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'fecha_desde' => '2026-06-05',
        ]))->assertOk()->json();

        $this->assertCount(1, $conFiltro['data']);
        $this->assertEquals(15, $conFiltro['data'][0]['stock_saldo']);
    }

    public function test_kpis_coinciden_con_la_valorizacion_de_stocks_y_productos(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central']);
        $p1 = Producto::factory()->create(['costo' => 100, 'precio_venta' => 200]);
        $p2 = Producto::factory()->create(['costo' => 50, 'precio_venta' => 80]);
        Stock::create(['producto_id' => $p1->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);
        Stock::create(['producto_id' => $p2->id, 'deposito_id' => $deposito->id, 'cantidad' => 5]);

        $resp = $this->getJson(route('informes.stock.stats'))->assertOk()->json();

        $this->assertEquals(15, $resp['unidades_en_stock']);
        $this->assertEquals(10 * 100 + 5 * 50, $resp['costo_total']);
        $this->assertEquals(10 * 200 + 5 * 80, $resp['valor_venta_total']);
    }

    public function test_filtro_operacion_solo_reconoce_ajuste_o_transferencia(): void
    {
        $deposito = Deposito::create(['nombre' => 'D']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 10, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 2, 'fecha' => '2026-06-02',
        ]);

        // "entrada" no es una opción reconocida (FR-013): el backend la ignora y
        // devuelve el histórico completo en vez de filtrar por ese tipo.
        $resp = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'entrada',
        ]))->assertOk()->json();
        $this->assertCount(2, $resp['data']);

        // "ajuste" sí es una opción reconocida y filtra correctamente.
        $resp2 = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'ajuste',
        ]))->assertOk()->json();
        $this->assertCount(1, $resp2['data']);
        $this->assertSame('ajuste', $resp2['data'][0]['tipo']);
    }

    public function test_filtro_producto_id_acota_la_tabla(): void
    {
        $deposito = Deposito::create(['nombre' => 'D']);
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $productoA->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 3, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $productoB->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 4, 'fecha' => '2026-06-01',
        ]);

        $resp = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'producto_id' => $productoA->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertSame($productoA->id, $resp['data'][0]['producto_id']);
    }
}
