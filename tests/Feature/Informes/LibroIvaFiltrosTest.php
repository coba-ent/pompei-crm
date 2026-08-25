<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Venta;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** spec 077 — filtros del Libro IVA (FR-026/027/028/031) y validación de período (FR-007). */
class LibroIvaFiltrosTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(array $extra = []): Request
    {
        return Request::create('/informes/contador/ventas/data', 'POST', array_merge(['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true], $extra));
    }

    /** FR-022b: borrado lógico queda fuera. */
    public function test_venta_con_borrado_logico_queda_fuera(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-10']);
        $venta->delete();

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->request())->get();

        $this->assertCount(0, $filas);
    }

    /** FR-007: sin mes/año, 422 con mensaje — a nivel HTTP. */
    public function test_data_sin_periodo_responde_422(): void
    {
        $response = $this->postJson('/informes/contador/ventas/data', []);

        $response->assertStatus(422)->assertJson(['message' => 'Elegí un mes y un año para generar el informe.']);
    }

    public function test_stats_sin_periodo_responde_422(): void
    {
        $response = $this->postJson('/informes/contador/ventas/stats', []);

        $response->assertStatus(422);
    }

    /** FR-027: filtrar por Condición de IVA acota tabla y (por extensión) totales. */
    public function test_filtro_condicion_iva_acota_resultado(): void
    {
        $rc = CondicionIva::firstOrCreate(['nombre' => 'Responsable Inscripto'], ['codigo_afip' => '1']);
        $cf = CondicionIva::firstOrCreate(['nombre' => 'Consumidor Final'], ['codigo_afip' => '5']);

        Venta::factory()->create(['cliente_id' => Cliente::factory()->create(['condicion_iva_id' => $rc->id]), 'fecha_emision' => '2026-08-10']);
        Venta::factory()->create(['cliente_id' => Cliente::factory()->create(['condicion_iva_id' => $cf->id]), 'fecha_emision' => '2026-08-11']);

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->request(['condicion_iva_id' => [$rc->id]]))->get();

        $this->assertCount(1, $filas);
        $this->assertSame('Responsable Inscripto', $filas->first()->condicion_iva);
    }

    /** FR-031: una venta con varios cobros filtrada por medio de cobro aparece UNA sola vez. */
    public function test_venta_con_varios_cobros_no_se_multiplica(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-10', 'total' => 300]);

        Cobro::factory()->create(['venta_id' => $venta->id, 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 100]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 200]);

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->request(['cuenta_tesoreria_id' => $cuenta->id]))->get();

        $this->assertCount(1, $filas, 'Con EXISTS, no con JOIN: nunca se duplica por cobro.');
    }

    /** FR-028: N° de Comprobante y CUIT coinciden parcialmente. */
    public function test_filtros_de_comprobante_y_cuit_son_parciales(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '20304050607']);
        Venta::factory()->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-08-10', 'nro_comprobante' => '0001-00001234']);

        $porNro = app(LibroIvaVentasQuery::class)->detalle($this->request(['nro_comprobante' => '1234']))->get();
        $porCuit = app(LibroIvaVentasQuery::class)->detalle($this->request(['cuit' => '304050']))->get();

        $this->assertCount(1, $porNro);
        $this->assertCount(1, $porCuit);
    }
}
