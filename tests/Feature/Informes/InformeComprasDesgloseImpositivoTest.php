<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraConcepto;
use App\Models\CompraItem;
use App\Services\Informes\ComprasInformeQuery;
use App\Services\Informes\DesgloseImpositivoCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * spec 067 US1 — desglose impositivo AFIP del Informe de Compras.
 *
 * Constitución III: los importes fiscales que se muestran tienen que reconstruir exactamente el
 * comprobante. Estos son los tests que lo garantizan.
 */
class InformeComprasDesgloseImpositivoTest extends TestCase
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
        return Request::create('/informes/compras', 'GET', $params);
    }

    /** @param  list<array{cantidad: float, precio: float, iva_pct: ?string}>  $lineas */
    private function compraCon(array $lineas, array $conceptos = []): Compra
    {
        $neto = 0.0;
        $iva = 0.0;

        foreach ($lineas as $l) {
            $subtotal = $l['cantidad'] * $l['precio'];
            $pct = is_numeric($l['iva_pct']) ? (float) $l['iva_pct'] : 0.0;
            $neto += $subtotal;
            $iva += $subtotal * $pct / 100;
        }

        $extras = array_sum(array_column($conceptos, 'monto'));

        $compra = Compra::factory()->create([
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => round($neto, 2),
            'subtotal_con_descuento' => round($neto, 2),
            'total' => round($neto + $iva + $extras, 2),
        ]);

        foreach ($lineas as $l) {
            $subtotal = $l['cantidad'] * $l['precio'];
            $pct = is_numeric($l['iva_pct']) ? (float) $l['iva_pct'] : 0.0;

            CompraItem::create([
                'compra_id' => $compra->id,
                'descripcion' => 'Ítem',
                'cantidad' => $l['cantidad'],
                'precio_unitario' => $l['precio'],
                'iva_pct' => $l['iva_pct'],
                'subtotal' => round($subtotal, 2),
                'subtotal_con_iva' => round($subtotal * (1 + $pct / 100), 2),
            ]);
        }

        foreach ($conceptos as $c) {
            CompraConcepto::create(array_merge(['compra_id' => $compra->id], $c));
        }

        return $compra;
    }

    /** @return Collection<int, \stdClass> */
    private function filas()
    {
        return app(ComprasInformeQuery::class)->detalle($this->request())->get();
    }

    /**
     * Invariante fiscal de data-model.md §2: los tres netos + el IVA de cada alícuota +
     * percepciones + impuestos internos + intereses reconstruyen el Total Compra, al centavo.
     */
    public function test_iva_por_alicuota_reconstruye_el_total(): void
    {
        $compra = $this->compraCon([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
            ['cantidad' => 2, 'precio' => 500, 'iva_pct' => '10.5'],
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => 'exento'],
            ['cantidad' => 1, 'precio' => 200, 'iva_pct' => 'no_gravado'],
        ], [
            ['tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 50],
            ['tipo' => 'impuesto_interno', 'concepto' => 'Impuestos internos', 'monto' => 30],
            ['tipo' => 'interes', 'concepto' => 'Interés financiero', 'monto' => 20],
        ]);

        $filas = $this->filas();
        $this->assertCount(4, $filas);

        // Los netos y el IVA son de ítem: se suman entre las filas de la compra.
        $netos = $filas->sum(fn ($f) => (float) $f->neto_gravado + (float) $f->neto_exento + (float) $f->neto_no_gravado);
        $ivas = $filas->sum(fn ($f) => (float) $f->iva_2_5 + (float) $f->iva_5 + (float) $f->iva_10_5 + (float) $f->iva_21 + (float) $f->iva_27);

        // Percepciones e impuestos internos son de comprobante: se repiten en cada fila, así que
        // se toman una sola vez (misma regla que "Total Comprobante").
        $primera = $filas->first();
        $percepciones = (float) $primera->perc_iva + (float) $primera->perc_iibb + (float) $primera->otras_percepciones;
        $intereses = 20.0;

        $this->assertEqualsWithDelta(2500.0, $netos, 0.01, '1000 gravado + 1000 gravado + 300 exento + 200 no gravado');
        $this->assertEqualsWithDelta(315.0, $ivas, 0.01, '21% de 1000 + 10,5% de 1000');
        $this->assertEqualsWithDelta(
            (float) $compra->total,
            $netos + $ivas + $percepciones + (float) $primera->imp_internos + $intereses,
            0.01,
            'El desglose tiene que reconstruir el Total Compra exactamente (constitución III).'
        );
    }

    public function test_cada_alicuota_cae_en_su_columna(): void
    {
        $this->compraCon([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '27'],
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '5'],
        ]);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(210.0, $filas->sum(fn ($f) => (float) $f->iva_21), 0.01);
        $this->assertEqualsWithDelta(270.0, $filas->sum(fn ($f) => (float) $f->iva_27), 0.01);
        $this->assertEqualsWithDelta(50.0, $filas->sum(fn ($f) => (float) $f->iva_5), 0.01);
        $this->assertEqualsWithDelta(0.0, $filas->sum(fn ($f) => (float) $f->iva_10_5), 0.01);
        // Existe la columna aunque el CRM no ofrezca todavía esa alícuota al cargar una compra.
        $this->assertEqualsWithDelta(0.0, $filas->sum(fn ($f) => (float) $f->iva_2_5), 0.01);
    }

    public function test_netos_se_clasifican_por_el_marcador_de_iva(): void
    {
        $this->compraCon([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => 'exento'],
            ['cantidad' => 1, 'precio' => 200, 'iva_pct' => 'no_gravado'],
            // `NULL` es el otro marcador de No Gravado que documenta data-model.md §2.
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => null],
        ]);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(1000.0, $filas->sum(fn ($f) => (float) $f->neto_gravado), 0.01);
        $this->assertEqualsWithDelta(300.0, $filas->sum(fn ($f) => (float) $f->neto_exento), 0.01);
        $this->assertEqualsWithDelta(300.0, $filas->sum(fn ($f) => (float) $f->neto_no_gravado), 0.01, 'no_gravado + NULL');
    }

    /** FR-015b: las tres columnas de percepción suman siempre el total de percepciones. */
    public function test_clasificacion_de_percepciones_no_pierde_importes(): void
    {
        $this->compraCon([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => null]], [
            ['tipo' => 'percepcion', 'concepto' => 'Percepción IVA', 'monto' => 30],
            ['tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 40],
            ['tipo' => 'percepcion', 'concepto' => 'Ingresos Brutos CABA', 'monto' => 25],
            ['tipo' => 'percepcion', 'concepto' => 'Tasa municipal', 'monto' => 15],
        ]);

        $fila = $this->filas()->first();

        $this->assertEqualsWithDelta(30.0, (float) $fila->perc_iva, 0.01);
        $this->assertEqualsWithDelta(65.0, (float) $fila->perc_iibb, 0.01, 'IIBB + Ingresos Brutos');
        $this->assertEqualsWithDelta(15.0, (float) $fila->otras_percepciones, 0.01, 'Lo no clasificable no se descarta.');
        $this->assertEqualsWithDelta(
            110.0,
            (float) $fila->perc_iva + (float) $fila->perc_iibb + (float) $fila->otras_percepciones,
            0.01,
            'Ninguna percepción puede perderse en la clasificación.'
        );
    }

    /**
     * "Percepción IIBB s/ IVA" contiene las dos palabras. Es de Ingresos Brutos, no de IVA: por
     * eso IIBB se evalúa primero, tanto en PHP como en SQL.
     */
    public function test_percepcion_ambigua_se_imputa_a_iibb(): void
    {
        $servicio = app(DesgloseImpositivoCompra::class);

        $this->assertSame('perc_iibb', $servicio->clasificarPercepcion('Percepción IIBB s/ IVA'));
        $this->assertSame('perc_iva', $servicio->clasificarPercepcion('PERCEPCIÓN IVA'));
        $this->assertSame('perc_iibb', $servicio->clasificarPercepcion('percepcion ingresos brutos'));
        $this->assertSame('otras_percepciones', $servicio->clasificarPercepcion('Tasa de seguridad e higiene'));
        // Palabra completa, no substring: "activa" contiene "iva" y no puede caer en Perc. IVA.
        $this->assertSame('otras_percepciones', $servicio->clasificarPercepcion('Retención activa'));
    }

    public function test_clasificacion_php_y_sql_coinciden(): void
    {
        $conceptos = ['Percepción IVA', 'Percepción IIBB s/ IVA', 'Ing. Brutos', 'Tasa municipal', 'Retención activa'];

        foreach ($conceptos as $indice => $concepto) {
            $this->compraCon([['cantidad' => 1, 'precio' => 100, 'iva_pct' => null]], [
                ['tipo' => 'percepcion', 'concepto' => $concepto, 'monto' => 10],
            ]);
        }

        $filas = $this->filas();
        $servicio = app(DesgloseImpositivoCompra::class);

        foreach ($conceptos as $indice => $concepto) {
            $esperada = $servicio->clasificarPercepcion($concepto);
            $fila = $filas[$indice];

            $this->assertEqualsWithDelta(
                10.0,
                (float) $fila->{$esperada},
                0.01,
                "El SQL tiene que imputar «{$concepto}» a la misma columna que clasificarPercepcion()."
            );
        }
    }

    /**
     * Bonificación del proveedor: un ítem con cantidad negativa resta en todas las columnas con
     * su signo, no se ignora ni se toma en valor absoluto.
     */
    public function test_item_con_cantidad_negativa_resta_con_su_signo(): void
    {
        $this->compraCon([
            ['cantidad' => 10, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => -2, 'precio' => 100, 'iva_pct' => '21'],
        ]);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(800.0, $filas->sum(fn ($f) => (float) $f->neto_gravado), 0.01);
        $this->assertEqualsWithDelta(168.0, $filas->sum(fn ($f) => (float) $f->iva_21), 0.01);

        $kpis = app(ComprasInformeQuery::class)->kpis($this->request());
        $this->assertSame(8.0, $kpis['cantidad_prod_serv'], '10 − 2 = 8 unidades.');
    }
}
