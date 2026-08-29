<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Gasto;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

/**
 * SC-002: el CSV y el PDF de cada informe reflejan exactamente las filas y
 * totales que muestra la pantalla para el mismo filtro. Arranca con Ventas
 * (T011); el resto de los informes amplía esta cobertura en Polish (T045).
 */
class InformeExportConsistencyTest extends TestCase
{
    use ActuaComoUsuarioConPermisos;
    use RefreshDatabase;

    /**
     * spec 090: cada informe exige su permiso y las descargas además `informes.exportar`. Este test
     * compara pantalla contra export en varios informes, así que necesita todos.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUsuarioConPermisos([
            'informes.ventas', 'informes.compras', 'informes.gastos', 'informes.stock',
            'informes.cuenta-corriente-clientes', 'informes.cuenta-corriente-proveedores',
            'informes.reporte-final', 'informes.contador', 'informes.exportar',
        ]);
    }

    public function test_ventas_csv_refleja_las_mismas_filas_y_totales_que_la_pantalla(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Export']);
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-05',
            'subtotal' => 1000,
            'total' => 1210,
        ]);
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-18',
            'subtotal' => 2000,
            'total' => 2420,
        ]);

        $pantalla = $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.ventas.csv', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $contenido = $csv->streamedContent();
        $lineas = array_values(array_filter(explode("\n", str_replace("\r", '', $contenido))));

        // Encabezado + 2 filas de detalle.
        $this->assertCount(3, $lineas);
        $this->assertCount(2, $pantalla['data']);
        $this->assertStringContainsString('Cliente Export', $lineas[1]);

        // La suma de la columna Total del CSV coincide con el total_general de la pantalla (SC-002).
        $sumaCsv = 0.0;
        foreach (array_slice($lineas, 1) as $linea) {
            $columnas = str_getcsv($linea, ';');
            $sumaCsv += (float) str_replace(',', '.', end($columnas));
        }
        $this->assertEqualsWithDelta($pantalla['total_general']['total'], $sumaCsv, 0.001);
    }

    public function test_ventas_pdf_se_genera_inline_para_el_modal_compartido(): void
    {
        $cliente = Cliente::factory()->create();
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-05',
            'subtotal' => 1000,
            'total' => 1210,
        ]);

        $pdf = $this->get(route('informes.ventas.pdf', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $pdf->headers->get('content-disposition'));
    }

    public function test_gastos_csv_refleja_el_total_general_de_la_pantalla(): void
    {
        $categoria = Categoria::factory()->create(['nombre' => 'Categoría Export']);
        Gasto::factory()->create(['categoria_id' => $categoria->id, 'fecha' => '2026-06-05', 'importe' => 750, 'estado' => 'pagado']);

        $pantalla = $this->getJson(route('informes.gastos.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.gastos.csv', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $contenido = str_replace("\r\n", '', $csv->streamedContent());
        $this->assertStringContainsString('Categoría Export', $contenido);
        $this->assertStringContainsString(
            number_format($pantalla['total_general'], 2, ',', ''),
            $contenido
        );
    }

    public function test_stock_csv_refleja_los_movimientos_de_la_pantalla(): void
    {
        $deposito = Deposito::create(['nombre' => 'Depósito Export', 'activo' => true]);
        $producto = Producto::factory()->create(['nombre' => 'Producto Export']);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 7, 'fecha' => '2026-06-10',
        ]);

        $pantalla = $this->getJson(route('informes.stock.data', [
            'producto_id' => $producto->id, 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.stock.csv', [
            'producto_id' => $producto->id, 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $this->assertCount(1, $pantalla['data']);
        $this->assertStringContainsString('Depósito Export', $csv->streamedContent());
    }

    public function test_ranking_csv_refleja_el_mismo_orden_que_la_pantalla(): void
    {
        $producto = Producto::factory()->create(['nombre' => 'Producto Ranking Export']);
        $venta = Venta::factory()->create(['fecha_emision' => '2026-06-10']);
        VentaItem::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id,
            'cantidad' => 5, 'subtotal' => 1000, 'total' => 1210,
        ]);

        $pantalla = $this->getJson(route('informes.ranking.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30', 'dato' => 'total', 'periodicidad' => 'mensual',
        ]))->assertOk()->json();

        $csv = $this->get(route('informes.ranking.csv', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30', 'dato' => 'total', 'periodicidad' => 'mensual',
        ]))->assertOk();

        $this->assertEquals('Producto Ranking Export', $pantalla['ranking'][0]['producto']);
        $this->assertStringContainsString('Producto Ranking Export', $csv->streamedContent());
    }
}
