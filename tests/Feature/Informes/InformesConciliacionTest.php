<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Gasto;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Services\Informes\ComprasInformeQuery;
use App\Services\Informes\GastosInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * spec 067 SC-004 — los informes tienen que conciliar al centavo con las pantallas de origen.
 *
 * Un informe que no cuadra con el listado del que sale es peor que no tener informe: hace perder
 * horas buscando un descuadre que no existe. Estos tests fijan esa equivalencia.
 */
class InformesConciliacionTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->autenticarConPermisoInformes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(array $params = []): Request
    {
        return Request::create('/informes', 'GET', $params);
    }

    private function compra(float $neto, float $total, string $fecha = '2026-08-10', ?Proveedor $proveedor = null): Compra
    {
        $compra = Compra::factory()->create([
            'proveedor_id' => ($proveedor ?? Proveedor::factory()->create())->id,
            'fecha_emision' => $fecha,
            'subtotal_sin_descuento' => $neto,
            'subtotal_con_descuento' => $neto,
            'total' => $total,
        ]);

        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => $neto, 'iva_pct' => '21', 'subtotal' => $neto, 'subtotal_con_iva' => $total,
        ]);

        return $compra;
    }

    /** Informe de Compras vs. el listado de Compras, sobre el mismo período. */
    public function test_total_de_compras_coincide_con_el_listado_de_compras(): void
    {
        $this->compra(1000, 1210);
        $this->compra(2000, 2420);
        $this->compra(500, 605, '2026-07-10'); // fuera del mes: no tiene que sumar en ninguno

        $delListado = (float) Compra::query()
            ->whereBetween('fecha_emision', ['2026-08-01', '2026-08-31'])
            ->sum('total');

        $delInforme = app(ComprasInformeQuery::class)->kpis($this->request())['total_compras_creadas'];

        $this->assertEqualsWithDelta($delListado, $delInforme, 0.01);
        $this->assertEqualsWithDelta(3630.0, $delInforme, 0.01);
    }

    /** Informe de Gastos vs. el listado de Gastos. */
    public function test_gasto_total_coincide_con_el_listado_de_gastos(): void
    {
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Oficina', 'activo' => true]);
        $sub = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Luz', 'categoria_padre_id' => $categoria->id, 'activo' => true]);

        Gasto::factory()->create(['fecha' => '2026-08-03', 'monto' => 300, 'categoria_id' => $sub->id]);
        Gasto::factory()->create(['fecha' => '2026-08-20', 'monto' => 200, 'categoria_id' => $categoria->id]);
        Gasto::factory()->create(['fecha' => '2026-07-20', 'monto' => 999, 'categoria_id' => $categoria->id]);

        $delListado = (float) Gasto::query()
            ->whereBetween('fecha', ['2026-08-01', '2026-08-31'])
            ->sum('monto');

        $delInforme = app(GastosInformeQuery::class)->subtotales($this->request())['gasto_total'];

        $this->assertEqualsWithDelta($delListado, $delInforme, 0.01);
        $this->assertEqualsWithDelta(500.0, $delInforme, 0.01);
    }

    /**
     * Cta Cte Proveedores vs. el bloque de cuentas a pagar: el total del tab Saldos tiene que ser
     * la deuda real con proveedores, calculada por el camino independiente de la propia consulta.
     */
    public function test_saldos_de_proveedores_coincide_con_la_deuda_calculada_aparte(): void
    {
        $proveedor = Proveedor::factory()->create();

        $compra = $this->compra(3000, 3630, '2026-08-01', $proveedor);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito', 'monto' => 130,
        ]);

        $this->compra(1000, 1210, '2026-08-05', $proveedor);

        $deudaReal = (float) DB::table('compras')
            ->whereNull('deleted_at')
            ->selectRaw(
                'SUM(compras.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0)'.
                ') as deuda'
            )
            ->value('deuda');

        $delInforme = collect($this->getJson(route('informes.cuenta-corriente-proveedores.saldos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50,
        ]))->assertOk()->json('data'))->sum('total');

        $this->assertEqualsWithDelta($deudaReal, (float) $delInforme, 0.01);
        $this->assertEqualsWithDelta(3710.0, (float) $delInforme, 0.01, '3630 − 1000 − 130 + 1210');
    }

    /**
     * El Excel y el PDF salen de los mismos servicios y con los mismos filtros que la pantalla,
     * así que sus totales no pueden divergir de los KPIs (FR-043).
     */
    public function test_los_kpis_no_cambian_entre_pantalla_y_exportacion(): void
    {
        $this->compra(1000, 1210);

        $filtros = ['fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31'];

        $dePantalla = $this->getJson(route('informes.compras.stats', $filtros))->assertOk()->json();
        $deServicio = app(ComprasInformeQuery::class)->kpis($this->request($filtros));

        $this->assertEqualsWithDelta($dePantalla['total_compras'], $deServicio['total_compras'], 0.01);
        $this->assertEqualsWithDelta($dePantalla['costo_actual'], $deServicio['costo_actual'], 0.01);
    }
}
