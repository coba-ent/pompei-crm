<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardRankingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ranking_de_clientes_ordenado_desc_por_monto_vendido(): void
    {
        $clienteA = Cliente::factory()->create();
        $clienteB = Cliente::factory()->create();
        $clienteC = Cliente::factory()->create();

        Venta::factory()->create(['cliente_id' => $clienteA->id, 'fecha_emision' => '2026-06-05', 'total' => 500]);
        Venta::factory()->create(['cliente_id' => $clienteB->id, 'fecha_emision' => '2026-06-06', 'total' => 2000]);
        Venta::factory()->create(['cliente_id' => $clienteC->id, 'fecha_emision' => '2026-06-07', 'total' => 1000]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals($clienteB->nombre, $resp['clientes'][0]['nombre']);
        $this->assertEquals($clienteC->nombre, $resp['clientes'][1]['nombre']);
        $this->assertEquals($clienteA->nombre, $resp['clientes'][2]['nombre']);
    }

    public function test_ranking_de_productos_ordenado_desc_por_cantidad_vendida(): void
    {
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        $venta1 = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        VentaItem::create([
            'venta_id' => $venta1->id, 'producto_id' => $productoA->id, 'descripcion' => $productoA->nombre,
            'cantidad' => 3, 'precio_unitario' => 100, 'subtotal' => 300, 'subtotal_con_iva' => 363,
        ]);

        $venta2 = Venta::factory()->create(['fecha_emision' => '2026-06-06', 'total' => 2000]);
        VentaItem::create([
            'venta_id' => $venta2->id, 'producto_id' => $productoB->id, 'descripcion' => $productoB->nombre,
            'cantidad' => 10, 'precio_unitario' => 50, 'subtotal' => 500, 'subtotal_con_iva' => 605,
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals($productoB->nombre, $resp['productos'][0]['nombre']);
        $this->assertEquals(10.0, $resp['productos'][0]['cantidad']);
        $this->assertEquals($productoA->nombre, $resp['productos'][1]['nombre']);
    }

    public function test_ranking_de_productos_excluye_ventas_soft_deleted(): void
    {
        // Necesario desde la spec 012: al eliminar la Venta, StockDeVenta reintegra
        // stock del ítem con producto y precisa poder resolver un depósito.
        \App\Models\Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $producto = Producto::factory()->create();
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-05', 'total' => 1000]);
        VentaItem::create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'descripcion' => $producto->nombre,
            'cantidad' => 5, 'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 605,
        ]);
        $venta->delete();

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEmpty($resp['productos']);
    }
}
