<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\InformeVentasExport;
use App\Exports\Informes\ReporteFinalExport;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Gasto;
use App\Models\Producto;
use App\Services\Informes\ReporteFinalQuery;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 068 — las dos réplicas deliberadas de defectos de Contagram (R1 y R2).
 *
 * **Este test existe para que nadie las "arregle" por error.** El usuario decidió fidelidad total
 * a Contagram, incluso donde Contagram está mal, para que los números coincidan al comparar
 * contra la app original. Si alguna de estas aserciones falla, la pregunta correcta no es "¿cómo
 * corrijo el cálculo?" sino "¿el usuario cambió de opinión sobre replicar el bug?".
 *
 * Lo que el test fija no es sólo que la desviación exista: también, y sobre todo, que **no se
 * propague** más allá de la celda donde vive. Es la condición con la que el principio III de la
 * constitución (corrección fiscal innegociable) tolera estas réplicas.
 */
class ReplicasContagramTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

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

    /**
     * Escenario exacto del relevamiento (§11.2): venta de $370 con CMV $200, anulada por su NC.
     * En pantalla la NC da -170,00; en el Excel de Contagram, -570,00.
     */
    private function escenarioR1(): void
    {
        $producto = Producto::factory()->create(['costo' => 0]);

        $compra = Compra::factory()->create(['fecha_emision' => '2026-07-01']);
        CompraItem::create([
            'compra_id' => $compra->id, 'producto_id' => $producto->id,
            'descripcion' => 'Camisa', 'cantidad' => 1, 'precio_unitario' => 200,
            'subtotal' => 200, 'subtotal_con_iva' => 200,
        ]);

        $venta = $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 370]]);
        $this->nota($venta, 'credito', [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 370]]);
    }

    /** @return array{legible: array, plana: array} filas de las dos hojas, sin encabezado */
    private function hojasVentas(array $params = []): array
    {
        $export = new InformeVentasExport(
            app(VentasInformeQuery::class),
            Request::create('/informes/ventas', 'GET', $params)
        );

        $hojas = $export->sheets();

        return [
            'legible' => array_slice($hojas[0]->array(), 1),
            'plana' => array_slice($hojas[1]->array(), 1),
        ];
    }

    // -----------------------------------------------------------------------------------
    // R1 — "Resultado" de las líneas de Nota de Crédito en el Excel de Ventas
    // -----------------------------------------------------------------------------------

    public function test_r1_la_hoja_legible_suma_en_vez_de_restar_en_las_filas_de_nota_de_credito(): void
    {
        $this->escenarioR1();

        // Columna 10 (base 0) = "Resultado"; columna 0 = Id.
        $filas = $this->hojasVentas()['legible'];

        $venta = $filas[0];
        $nc = $filas[1];

        $this->assertEqualsWithDelta(170.0, $venta[10], 0.01, 'La fila de venta no se toca: Precio − CMV.');
        // -370 + -200 = -570. RÉPLICA DELIBERADA (spec §R1).
        $this->assertEqualsWithDelta(-570.0, $nc[10], 0.01, 'La réplica R1 desapareció del Excel.');
    }

    public function test_r1_la_hoja_plana_usa_la_formula_correcta_en_todas_las_filas(): void
    {
        $this->escenarioR1();

        // Columna 13 (base 0) = "Resultado" de la hoja plana.
        $filas = $this->hojasVentas()['plana'];

        $this->assertEqualsWithDelta(170.0, $filas[0][13], 0.01);
        // La hoja para reprocesar no puede arrastrar el desvío: -370 − (-200) = -170.
        $this->assertEqualsWithDelta(-170.0, $filas[1][13], 0.01);
    }

    public function test_r1_no_se_propaga_a_los_kpis(): void
    {
        $this->escenarioR1();

        $kpis = app(VentasInformeQuery::class)->kpis(Request::create('/informes/ventas', 'GET'));

        // Venta y NC se anulan: neto 0, CMV 0, resultado 0. Si el -570 se hubiera filtrado a los
        // agregados, ninguno de estos tres daría cero.
        $this->assertEqualsWithDelta(0.0, $kpis['precio_neto'], 0.01);
        $this->assertEqualsWithDelta(0.0, $kpis['cmv'], 0.01);
        $this->assertEqualsWithDelta(0.0, $kpis['resultado'], 0.01);
    }

    public function test_r1_no_se_propaga_a_la_pantalla(): void
    {
        $this->escenarioR1();

        $filas = app(VentasInformeQuery::class)
            ->detalle(Request::create('/informes/ventas', 'GET'))
            ->get()
            ->keyBy('tipo_operacion');

        $this->assertEqualsWithDelta(-170.0, (float) $filas['nc']->resultado, 0.01);
    }

    public function test_r1_solo_afecta_a_las_notas_de_credito_no_a_las_de_debito(): void
    {
        $producto = Producto::factory()->create(['costo' => 0]);
        $venta = $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]]);
        $this->nota($venta, 'debito', [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 50]]);

        $filas = $this->hojasVentas()['legible'];

        // Sin compras registradas el CMV es 0, así que la ND tiene que dar su propio neto.
        $this->assertEqualsWithDelta(50.0, $filas[1][10], 0.01);
    }

    // -----------------------------------------------------------------------------------
    // R2 — doble estándar de signos del Reporte Final en el Excel
    // -----------------------------------------------------------------------------------

    /** @return list<array> filas de la hoja legible, sin encabezado */
    private function hojaLegibleReporte(array $params = []): array
    {
        $export = new ReporteFinalExport(
            app(ReporteFinalQuery::class),
            Request::create('/informes/reporte-final', 'GET', $params)
        );

        return array_slice($export->sheets()[0]->array(), 1);
    }

    /** Busca el valor de la fila cuyo rótulo (ya sin sangría) coincide. */
    private function valor(array $filas, string $rotulo): ?float
    {
        foreach ($filas as $fila) {
            if (isset($fila[0]) && trim((string) $fila[0]) === $rotulo) {
                return $fila[1] === null ? null : (float) $fila[1];
            }
        }

        return null;
    }

    private function escenarioR2(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 1000]]);
        Gasto::factory()->create(['fecha' => '2026-08-04', 'monto' => 400]);
    }

    public function test_r2_la_hoja_devengado_trae_egresos_en_negativo_y_suma_el_resultado(): void
    {
        $this->escenarioR2();

        $filas = $this->hojaLegibleReporte(['vista' => 'devengado']);

        $ingresos = $this->valor($filas, 'Total Ingresos');
        $egresos = $this->valor($filas, 'Total Egresos');
        $resultado = $this->valor($filas, 'Resultado');

        $this->assertEqualsWithDelta(1000.0, $ingresos, 0.01);
        // RÉPLICA DELIBERADA (spec §R2): en esta hoja el egreso viaja NEGATIVO...
        $this->assertEqualsWithDelta(-400.0, $egresos, 0.01);
        // ...y el resultado se obtiene SUMANDO.
        $this->assertEqualsWithDelta(600.0, $resultado, 0.01);
        $this->assertEqualsWithDelta($ingresos + $egresos, $resultado, 0.01);
    }

    public function test_r2_la_hoja_caja_trae_egresos_en_positivo_y_resta_el_resultado(): void
    {
        $this->escenarioR2();

        $filas = $this->hojaLegibleReporte(['vista' => 'caja']);

        $ingresos = $this->valor($filas, 'Total Ingresos');
        $egresos = $this->valor($filas, 'Total Egresos');
        $resultado = $this->valor($filas, 'Resultado');

        // RÉPLICA DELIBERADA (spec §R2): la otra hoja usa la convención opuesta.
        $this->assertEqualsWithDelta(400.0, $egresos, 0.01);
        $this->assertEqualsWithDelta($ingresos - $egresos, $resultado, 0.01);
    }

    public function test_r2_los_subtotales_de_bloque_de_egresos_van_en_negativo_en_las_dos_hojas(): void
    {
        $this->escenarioR2();

        foreach (['devengado', 'caja'] as $vista) {
            $filas = $this->hojaLegibleReporte(['vista' => $vista]);

            $this->assertEqualsWithDelta(-400.0, $this->valor($filas, 'Total Gastos'), 0.01,
                "El subtotal de Gastos dejó de ir en negativo en la hoja {$vista}.");
        }
    }

    public function test_r2_en_la_hoja_caja_la_linea_por_cuenta_va_en_positivo_aunque_su_subtotal_sea_negativo(): void
    {
        // El detalle más fino de R2: dentro de un bloque cuyo subtotal es negativo, las líneas
        // individuales por cuenta de tesorería salen en positivo.
        $gasto = Gasto::factory()->create(['fecha' => '2026-08-04', 'monto' => 400]);
        $cuenta = $gasto->cuenta_tesoreria_id;

        $this->assertNotNull($cuenta);

        $filas = $this->hojaLegibleReporte(['vista' => 'caja']);
        $nombreCuenta = \App\Models\CuentaTesoreria::find($cuenta)->nombre;

        $this->assertEqualsWithDelta(400.0, $this->valor($filas, $nombreCuenta), 0.01);
        $this->assertEqualsWithDelta(-400.0, $this->valor($filas, 'Total Gastos'), 0.01);
    }

    public function test_r2_no_se_propaga_a_la_pantalla_ni_a_la_hoja_plana(): void
    {
        $this->escenarioR2();

        // Pantalla: egresos en positivo y resta, en las DOS vistas (FR-035).
        foreach (['devengado', 'caja'] as $vista) {
            $totales = app(ReporteFinalQuery::class)
                ->arbol(Request::create('/informes/reporte-final', 'GET', ['vista' => $vista]))['totales'];

            $this->assertGreaterThanOrEqual(0, $totales['egresos']);
            $this->assertEqualsWithDelta($totales['ingresos'] - $totales['egresos'], $totales['resultado'], 0.01);
        }

        // Hoja plana: todo en positivo, con la naturaleza en su propia columna.
        $export = new ReporteFinalExport(
            app(ReporteFinalQuery::class),
            Request::create('/informes/reporte-final', 'GET', ['vista' => 'devengado'])
        );
        $plana = array_slice($export->sheets()[1]->array(), 1);

        $this->assertNotEmpty($plana);

        foreach ($plana as $fila) {
            // Columna 2 = Naturaleza, columna 6 = Monto.
            $this->assertContains($fila[2], ['Ingreso', 'Egreso']);
            $this->assertGreaterThanOrEqual(0, $fila[6], 'La hoja plana tiene que salir siempre en positivo.');
        }
    }

    public function test_las_dos_hojas_completan_siempre_desde_y_hasta(): void
    {
        // Quirk de origen que se decidió NO replicar: Contagram deja esas celdas vacías en la
        // hoja de "Cobros Vs Pagos". Es una omisión sin valor informativo, no una regla de
        // cálculo (FR-039).
        $this->escenarioR2();

        foreach (['devengado', 'caja'] as $vista) {
            $filas = $this->hojaLegibleReporte(['vista' => $vista]);

            $this->assertSame('2026-08-01', $filas[0][1]);
            $this->assertSame('2026-08-31', $filas[1][1]);
        }
    }
}
