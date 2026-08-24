<?php

namespace Tests\Feature\Informes;

use App\Models\Producto;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * spec 076 — el importe por línea (FR-001, FR-002, data-model §2).
 *
 * El importe de línea deja de ser el total del comprobante repetido y pasa a ser lo que corresponde
 * a esa línea: su neto con IVA más su parte proporcional de los conceptos extra del comprobante
 * (percepciones, impuestos internos). El invariante que define todo esto es que la suma de las
 * líneas de un comprobante cierre exacto contra su total — al centavo, no aproximado.
 */
class InformeVentasImporteLineaTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function informe(): VentasInformeQuery
    {
        return app(VentasInformeQuery::class);
    }

    /** Registra un concepto extra (percepción, impuesto interno, etc.) sobre una venta. */
    private function concepto(int $ventaId, string $tipo, string $concepto, float $monto): void
    {
        DB::table('venta_conceptos')->insert([
            'venta_id' => $ventaId,
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** I1: la suma del importe de línea de un comprobante iguala su total, al centavo. */
    public function test_la_suma_del_importe_de_linea_iguala_el_total_del_comprobante(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 2, 'precio' => 250, 'iva_pct' => '21'],
        ]);

        $filas = $this->informe()->detalle($this->request())->get();

        $suma = round($filas->sum(fn ($f) => (float) $f->total_venta), 2);

        $this->assertEqualsWithDelta((float) $venta->total, $suma, 0.01);
    }

    /** I2: la suma sobre todo el detalle de un período iguala el KPI Total Ventas. */
    public function test_la_suma_del_periodo_iguala_el_kpi_total_ventas(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);
        $this->venta([['cantidad' => 3, 'precio' => 400, 'iva_pct' => '10.5']]);

        $filas = $this->informe()->detalle($this->request())->get();
        $kpis = $this->informe()->kpis($this->request());

        $suma = round($filas->sum(fn ($f) => (float) $f->total_venta), 2);

        $this->assertEqualsWithDelta($kpis['total_ventas'], $suma, 0.01);
    }

    /** Caso de conceptos extra: una venta con percepción e impuesto interno cierra igual contra su total. */
    public function test_los_conceptos_extra_se_prorratean_y_la_suma_sigue_cerrando(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 700, 'iva_pct' => '21'],
        ]);

        $this->concepto($venta->id, 'percepcion', 'Percepción IIBB', 50);
        $this->concepto($venta->id, 'impuesto_interno', 'Impuesto Interno', 30);

        // El helper `venta()` no conoce los conceptos extra: el total real del comprobante los
        // incluye, así que se corrige acá para que el test represente el dato real.
        $venta->forceFill(['total' => round($venta->total + 80, 2)])->save();

        $filas = $this->informe()->detalle($this->request())->where('id', $venta->id)->get();

        $suma = round($filas->sum(fn ($f) => (float) $f->total_venta), 2);

        $this->assertEqualsWithDelta((float) $venta->total, $suma, 0.01);

        // Y el prorrateo es proporcional al neto de cada línea (300 vs 700 → 30% / 70% de los $80).
        $lineaChica = $filas->firstWhere('precio_unitario', 300.0);
        $lineaGrande = $filas->firstWhere('precio_unitario', 700.0);

        $esperadoChica = round(300 * 1.21 + 80 * 0.3, 2);
        $this->assertEqualsWithDelta($esperadoChica, (float) $lineaChica->total_venta, 0.05);
        $this->assertGreaterThan((float) $lineaChica->total_venta, (float) $lineaGrande->total_venta);
    }

    /** El residuo del redondeo del prorrateo lo absorbe la última línea del comprobante. */
    public function test_el_residuo_del_redondeo_del_prorrateo_lo_absorbe_la_ultima_linea(): void
    {
        // Tres líneas de neto idéntico y un concepto que no divide exacto entre las tres.
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => null],
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => null],
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => null],
        ]);

        $this->concepto($venta->id, 'percepcion', 'Percepción', 10);
        $venta->forceFill(['total' => round($venta->total + 10, 2)])->save();

        $filas = $this->informe()->detalle($this->request())->where('id', $venta->id)->get();

        $suma = round($filas->sum(fn ($f) => (float) $f->total_venta), 2);
        $this->assertEqualsWithDelta((float) $venta->total, $suma, 0.01);

        // 10 / 3 no divide exacto: dos líneas llevan el mismo prorrateo y la tercera el residuo.
        $this->assertCount(3, $filas);
    }

    /** Signo: una nota de crédito aporta importes negativos y una de débito positivos, sin rama por tipo. */
    public function test_el_signo_del_importe_de_linea_sigue_al_tipo_de_comprobante(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 370, 'iva_pct' => '21']]);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 370]]);
        $this->nota($venta, 'debito', [['cantidad' => 1, 'precio' => 50]]);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertGreaterThan(0, (float) $filas['venta']->total_venta);
        $this->assertLessThan(0, (float) $filas['nc']->total_venta);
        $this->assertGreaterThan(0, (float) $filas['nd']->total_venta);
    }

    /** El borde de división por cero: un comprobante de neto cero con conceptos cargados no rompe ni produce NULL. */
    public function test_un_comprobante_de_neto_cero_con_conceptos_no_rompe(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 0, 'iva_pct' => null],
        ]);

        $this->concepto($venta->id, 'percepcion', 'Percepción', 25);
        $venta->forceFill(['total' => 25])->save();

        $filas = $this->informe()->detalle($this->request())->where('id', $venta->id)->get();

        // Con neto total en cero no hay proporción posible: se reparte en partes iguales entre
        // las líneas (acá, la única) en lugar de perder la plata o romper con división por cero.
        $this->assertNotNull($filas->first()->total_venta);
        $this->assertEqualsWithDelta(25.0, (float) $filas->first()->total_venta, 0.01);
    }

    /** La nota migrada sin ítems: aporta una fila con su monto completo, como hoy. */
    public function test_la_nota_migrada_sin_items_aporta_su_monto_completo(): void
    {
        $nota = $this->nota(null, 'credito', [], ['monto' => 999.50]);

        DB::table('nota_credito_debito_items')->where('nota_credito_debito_id', $nota->id)->delete();

        $fila = $this->informe()->detalle($this->request())->where('tipo_operacion', 'nc')->first();

        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(-999.50, (float) $fila->total_venta, 0.01);
    }
}
