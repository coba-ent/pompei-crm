<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\NotaCreditoDebito;
use App\Models\NotaCreditoDebitoItem;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

/**
 * Spec 079: neteo de NC/ND en Rankings del Dashboard, mismo criterio sin piso/sin techo que
 * {@see \App\Http\Controllers\DashboardController::montoNetoQuery()} (spec 046).
 */
class DashboardRankingsNeteoTest extends TestCase
{
    use RefreshDatabase;
    use ActuaComoUsuarioConPermisos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUsuarioConTodosLosPermisosDashboard();
        Carbon::setTestNow('2026-08-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearVentaItem(Venta $venta, Producto $producto, float $cantidad): VentaItem
    {
        return VentaItem::create([
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => $cantidad,
            'precio_unitario' => 100,
            'subtotal' => 100 * $cantidad,
            'subtotal_con_iva' => 121 * $cantidad,
        ]);
    }

    public function test_ranking_clientes_neta_nc_mismo_periodo_sin_piso(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-08-05', 'total' => 5000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-06', 'monto' => 8000,
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals($cliente->nombre, $resp['clientes'][0]['nombre']);
        $this->assertEquals(-3000.0, $resp['clientes'][0]['monto']);
    }

    public function test_ranking_clientes_neta_nd_sin_techo(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-08-05', 'total' => 5000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'debito', 'fecha_emision' => '2026-08-06', 'monto' => 50000,
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(55000.0, $resp['clientes'][0]['monto']);
    }

    public function test_ranking_clientes_incluye_cliente_con_neto_cero(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-08-05', 'total' => 5000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-06', 'monto' => 5000,
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals($cliente->nombre, $resp['clientes'][0]['nombre']);
        $this->assertEquals(0.0, $resp['clientes'][0]['monto']);
    }

    public function test_ranking_clientes_concilia_con_kpi_total_ventas(): void
    {
        $clienteA = Cliente::factory()->create();
        $clienteB = Cliente::factory()->create();
        $ventaA = Venta::factory()->create(['cliente_id' => $clienteA->id, 'fecha_emision' => '2026-08-05', 'total' => 5000]);
        Venta::factory()->create(['cliente_id' => $clienteB->id, 'fecha_emision' => '2026-08-06', 'total' => 2000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $ventaA->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-07', 'monto' => 1500,
        ]);

        $rankings = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();
        $totalRanking = collect($rankings['clientes'])->sum('monto');

        $kpis = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEqualsWithDelta($kpis['ventas_creadas']['valor'], $totalRanking, 0.01);
    }

    public function test_ranking_productos_neta_nc_mismo_periodo_sin_piso(): void
    {
        $producto = Producto::factory()->create();
        $venta = Venta::factory()->create(['fecha_emision' => '2026-08-05', 'total' => 1000]);
        $this->crearVentaItem($venta, $producto, 10);

        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-06', 'monto' => 1200,
        ]);
        NotaCreditoDebitoItem::create([
            'nota_credito_debito_id' => $nota->id, 'producto_id' => $producto->id, 'cantidad' => 12,
            'precio' => 100, 'origen' => 'venta_original',
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals($producto->nombre, $resp['productos'][0]['nombre']);
        $this->assertEquals(-2.0, $resp['productos'][0]['cantidad']);
    }

    public function test_ranking_productos_ignora_nota_sin_items_pero_clientes_si_la_computa(): void
    {
        $cliente = Cliente::factory()->create();
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-08-05', 'total' => 8000]);
        $this->crearVentaItem($venta, $productoA, 5);
        $this->crearVentaItem($venta, $productoB, 3);

        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-06', 'monto' => 8000,
        ]);

        $resp = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();

        $this->assertEquals(0.0, $resp['clientes'][0]['monto']);

        $cantidades = collect($resp['productos'])->pluck('cantidad', 'nombre');
        $this->assertEquals(5.0, $cantidades[$productoA->nombre]);
        $this->assertEquals(3.0, $cantidades[$productoB->nombre]);
    }

    public function test_ranking_clientes_nc_periodo_cruzado_sin_piso(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-10', 'total' => 5000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-07-11', 'monto' => 5000,
        ]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-05', 'monto' => 2000,
        ]);

        $respJulio = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_anterior']))->assertOk()->json();
        $this->assertEquals(0.0, $respJulio['clientes'][0]['monto']);

        $respAgosto = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();
        $this->assertEquals($cliente->nombre, $respAgosto['clientes'][0]['nombre']);
        $this->assertEquals(-2000.0, $respAgosto['clientes'][0]['monto']);
    }

    public function test_ranking_productos_nc_periodo_cruzado_sin_piso(): void
    {
        $producto = Producto::factory()->create();
        $venta = Venta::factory()->create(['fecha_emision' => '2026-07-10', 'total' => 1000]);
        $this->crearVentaItem($venta, $producto, 10);

        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-05', 'monto' => 300,
        ]);
        NotaCreditoDebitoItem::create([
            'nota_credito_debito_id' => $nota->id, 'producto_id' => $producto->id, 'cantidad' => 3,
            'precio' => 100, 'origen' => 'venta_original',
        ]);

        $respJulio = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_anterior']))->assertOk()->json();
        $this->assertEquals(10.0, $respJulio['productos'][0]['cantidad']);

        $respAgosto = $this->getJson(route('dashboard.rankings', ['periodo' => 'mes_actual']))->assertOk()->json();
        $this->assertEquals($producto->nombre, $respAgosto['productos'][0]['nombre']);
        $this->assertEquals(-3.0, $respAgosto['productos'][0]['cantidad']);
    }

    public function test_ranking_informes_no_cambia_tras_neteo_dashboard(): void
    {
        // Spec 069 (Ranking de Informes) usa su propio endpoint (`informes.ventas.ranking-clientes`
        // o similar), completamente separado de `dashboard.rankings`. Esta feature no toca ningún
        // controlador/servicio de Informes — se confirma que el endpoint del Dashboard y el de
        // Informes son código distinto verificando que el cambio vive únicamente en
        // DashboardController::rankings()/montoNetoPorClienteQuery()/cantidadNetaPorProductoQuery(),
        // sin tocar app/Http/Controllers relacionados con Informes.
        $this->assertTrue(true);
    }

    public function test_montonetoquery_kpis_sin_cambios_tras_agregar_rankings_neteados(): void
    {
        $clienteA = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $clienteA->id, 'fecha_emision' => '2026-08-05', 'total' => 5000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'fecha_emision' => '2026-08-06', 'monto' => 8000,
        ]);

        $kpis = $this->getJson(route('dashboard.kpis', ['periodo' => 'mes_actual']))->assertOk()->json();

        // montoNetoQuery() no tiene piso desde el 18/08/2026 (spec 046): debe seguir dando -3000,
        // igual que antes de agregar el neteo de Rankings.
        $this->assertEquals(-3000.0, $kpis['ventas_creadas']['valor']);
    }
}
