<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Informes\ComprasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 067 US1 — KPIs y filtros del Informe de Compras.
 *
 * Es todo cálculo de dinero, así que la constitución (principio IV) lo exige cubierto. Los tests
 * atacan el servicio directamente y no el endpoint: es la misma razón por la que el cálculo vive
 * fuera del controlador.
 */
class InformeComprasTest extends TestCase
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

    private function informe(): ComprasInformeQuery
    {
        return app(ComprasInformeQuery::class);
    }

    private function request(array $params = []): Request
    {
        return Request::create('/informes/compras', 'GET', $params);
    }

    /**
     * Compra con `$items` líneas de `$cantidad` unidades a `$precio`, con IVA `$ivaPct`.
     * El total del comprobante se graba coherente con sus ítems, como lo hace el alta real.
     */
    private function compra(array $lineas, array $atributos = []): Compra
    {
        $neto = 0.0;
        $conIva = 0.0;

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;
            $neto += $subtotal;
            $conIva += $subtotal * (1 + $pct / 100);
        }

        $compra = Compra::factory()->create(array_merge([
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => round($neto, 2),
            'subtotal_con_descuento' => round($neto, 2),
            'total' => round($conIva, 2),
        ], $atributos));

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;

            CompraItem::create([
                'compra_id' => $compra->id,
                'producto_id' => $linea['producto_id'] ?? null,
                'descripcion' => $linea['descripcion'] ?? 'Ítem',
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $linea['precio'],
                'iva_pct' => $linea['iva_pct'] ?? null,
                'subtotal' => round($subtotal, 2),
                'subtotal_con_iva' => round($subtotal * (1 + $pct / 100), 2),
            ]);
        }

        return $compra;
    }

    public function test_ecuacion_kpis(): void
    {
        $compra = $this->compra([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);

        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'debito',
            'monto' => 300, 'fecha_emision' => '2026-08-12',
        ]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito',
            'monto' => 121, 'fecha_emision' => '2026-08-12',
        ]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertSame(1210.0, $kpis['total_compras_creadas']);
        $this->assertSame(300.0, $kpis['total_nota_debito']);
        $this->assertSame(121.0, $kpis['total_nota_credito']);
        $this->assertSame(
            round($kpis['total_compras_creadas'] + $kpis['total_nota_debito'] - $kpis['total_nota_credito'], 2),
            $kpis['total_compras'],
            'Total Compras tiene que ser exactamente Creadas + ND − NC (FR-010).'
        );
        $this->assertSame(1389.0, $kpis['total_compras']);
    }

    /**
     * La trampa del informe: "Total Comprobante" se repite en cada fila de ítem. Si el KPI se
     * calculara sumando esa columna, una compra de 4 ítems contaría su total 4 veces.
     */
    public function test_total_comprobante_no_se_suma_por_fila(): void
    {
        $this->compra([
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
        ]);

        $filas = $this->informe()->detalle($this->request())->get();
        $kpis = $this->informe()->kpis($this->request());

        $this->assertCount(4, $filas, 'El detalle es una fila por ítem.');
        $this->assertSame(484.0, $kpis['total_compras_creadas']);
        $this->assertSame(1, $kpis['cantidad_compras_creadas']);
        // La suma ingenua de la columna del detalle da 4 veces el total: por eso no se usa.
        $this->assertEqualsWithDelta(1936.0, $filas->sum(fn ($f) => (float) $f->total_comprobante), 0.01);
    }

    public function test_cantidad_prod_serv_suma_cantidades(): void
    {
        $this->compra([
            ['cantidad' => 10, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 5, 'precio' => 100, 'iva_pct' => '21'],
        ]);

        $kpis = $this->informe()->kpis($this->request());

        // 15 unidades en 2 líneas: el KPI cuenta unidades, no líneas.
        $this->assertSame(15.0, $kpis['cantidad_prod_serv']);
    }

    public function test_compra_promedio_con_divisor_cero(): void
    {
        // Período sin compras: promedio 0, nunca una división por cero ni un error.
        $kpis = $this->informe()->kpis($this->request(['fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-01-31']));

        $this->assertSame(0, $kpis['cantidad_compras_creadas']);
        $this->assertSame(0.0, $kpis['compra_promedio']);
        $this->assertSame(0.0, $kpis['total_compras']);
    }

    public function test_compra_promedio_divide_el_total_por_la_cantidad(): void
    {
        $this->compra([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => null]]);
        $this->compra([['cantidad' => 1, 'precio' => 3000, 'iva_pct' => null]]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertSame(2, $kpis['cantidad_compras_creadas']);
        $this->assertSame(2000.0, $kpis['compra_promedio']);
    }

    /**
     * FR-016: NC y ND salen de la misma expresión, con el signo como único diferencial. No hay
     * una rama de cálculo por tipo de comprobante — que es el bug que el relevamiento encontró
     * en Contagram.
     */
    public function test_nota_credito_usa_la_misma_formula(): void
    {
        $compra = $this->compra([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => null]]);

        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito',
            'monto' => 250, 'fecha_emision' => '2026-08-11',
        ]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'debito',
            'monto' => 250, 'fecha_emision' => '2026-08-11',
        ]);

        $filas = $this->informe()->detalle($this->request())->get()
            ->keyBy(fn ($f) => $f->operacion);

        $this->assertEqualsWithDelta(-250.0, (float) $filas['nota_credito']->total_compra, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $filas['nota_debito']->total_compra, 0.01);

        // Importes simétricos ⇒ se cancelan en la ecuación de KPIs.
        $kpis = $this->informe()->kpis($this->request());
        $this->assertSame($kpis['total_compras_creadas'], $kpis['total_compras']);
    }

    public function test_compra_eliminada_no_aparece_ni_suma(): void
    {
        $viva = $this->compra([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => null]]);
        $muerta = $this->compra([['cantidad' => 1, 'precio' => 9999, 'iva_pct' => null]]);
        $muerta->delete();

        $filas = $this->informe()->detalle($this->request())->get();
        $kpis = $this->informe()->kpis($this->request());

        $this->assertCount(1, $filas);
        $this->assertSame($viva->id, (int) $filas->first()->id);
        $this->assertSame(1000.0, $kpis['total_compras_creadas']);
        $this->assertSame(1, $kpis['cantidad_compras_creadas']);
    }

    public function test_costo_actual_usa_el_costo_vigente_del_producto(): void
    {
        $producto = Producto::factory()->create(['costo' => 100]);
        $this->compra([['cantidad' => 3, 'precio' => 500, 'iva_pct' => null, 'producto_id' => $producto->id]]);

        $this->assertSame(300.0, $this->informe()->kpis($this->request())['costo_actual']);

        // Editar el costo cambia el KPI retroactivamente: es la semántica documentada (§5) y la
        // razón por la que la pantalla la explica con un tooltip obligatorio.
        $producto->update(['costo' => 150]);

        $this->assertSame(450.0, $this->informe()->kpis($this->request())['costo_actual']);
    }

    public function test_rango_por_defecto_es_el_mes_actual(): void
    {
        $this->compra([['cantidad' => 1, 'precio' => 100, 'iva_pct' => null]], ['fecha_emision' => '2026-08-10']);
        $this->compra([['cantidad' => 1, 'precio' => 999, 'iva_pct' => null]], ['fecha_emision' => '2026-07-10']);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertSame(100.0, $kpis['total_compras_creadas'], 'Sin rango explícito se informa el mes actual (FR-004b).');
    }

    public function test_filtros_combinan_and_entre_campos_y_or_dentro_del_campo(): void
    {
        $p1 = Proveedor::factory()->create();
        $p2 = Proveedor::factory()->create();
        $p3 = Proveedor::factory()->create();

        $this->compra([['cantidad' => 1, 'precio' => 100, 'iva_pct' => null, 'descripcion' => 'Caño PVC']], ['proveedor_id' => $p1->id]);
        $this->compra([['cantidad' => 1, 'precio' => 200, 'iva_pct' => null, 'descripcion' => 'Caño de cobre']], ['proveedor_id' => $p2->id]);
        $this->compra([['cantidad' => 1, 'precio' => 400, 'iva_pct' => null, 'descripcion' => 'Grifería']], ['proveedor_id' => $p3->id]);

        // OR dentro de "proveedor_id": los dos proveedores entran.
        $soloProveedores = $this->informe()->kpis($this->request(['proveedor_id' => [$p1->id, $p2->id]]));
        $this->assertSame(300.0, $soloProveedores['total_compras_creadas']);

        // AND contra el otro campo: de esos dos, sólo el que además dice "cobre".
        $combinado = $this->informe()->kpis($this->request([
            'proveedor_id' => [$p1->id, $p2->id],
            'producto_servicio' => 'cobre',
        ]));
        $this->assertSame(200.0, $combinado['total_compras_creadas']);
    }

    public function test_periodo_sin_datos_devuelve_200_con_kpis_en_cero(): void
    {
        $respuesta = $this->getJson(route('informes.compras.stats', [
            'fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-01-31',
        ]))->assertOk()->json();

        // assertEquals y no assertSame: al serializar a JSON el 0.0 vuelve como int 0.
        $this->assertEquals(0, $respuesta['total_compras']);
        $this->assertEquals(0, $respuesta['cantidad_compras_creadas']);
        $this->assertEquals(0, $respuesta['compra_promedio']);
    }

    public function test_rango_invertido_devuelve_422(): void
    {
        $this->getJson(route('informes.compras.stats', [
            'fecha_desde' => '2026-08-31', 'fecha_hasta' => '2026-08-01',
        ]))->assertStatus(422)->assertJsonStructure(['message']);
    }
}
