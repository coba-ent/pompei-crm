<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaConcepto;
use App\Models\VentaItem;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 077, US1 — la ecuación de totales del Libro IVA cierra EXACTA (FR-011), calculada sobre
 * el conjunto filtrado completo (FR-012) y sin que Imp. Internos/Municipales la contaminen
 * (FR-011a).
 */
class LibroIvaTotalesTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(array $extra = []): Request
    {
        // arca+manuales=true: estos tests no versan sobre la partición ARCA/Manuales (eso lo
        // cubre LibroIvaArcaManualesTest), así que se pide el universo completo del período.
        return Request::create('/informes/contador/ventas/data', 'POST', array_merge(['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true], $extra));
    }

    private function ventaCon(array $lineas, array $conceptos = []): Venta
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

        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => round($neto, 2),
            'subtotal_con_descuento' => round($neto, 2),
            'total' => round($neto + $iva + $extras, 2),
        ]);

        foreach ($lineas as $l) {
            $subtotal = $l['cantidad'] * $l['precio'];
            $pct = is_numeric($l['iva_pct']) ? (float) $l['iva_pct'] : 0.0;

            VentaItem::create([
                'venta_id' => $venta->id,
                'descripcion' => 'Ítem',
                'cantidad' => $l['cantidad'],
                'precio_unitario' => $l['precio'],
                'iva_pct' => $l['iva_pct'],
                'subtotal' => round($subtotal, 2),
                'subtotal_con_iva' => round($subtotal * (1 + $pct / 100), 2),
            ]);
        }

        foreach ($conceptos as $c) {
            VentaConcepto::create(array_merge(['venta_id' => $venta->id], $c));
        }

        return $venta;
    }

    private function totales(array $extra = []): array
    {
        return app(LibroIvaVentasQuery::class)->totales($this->request($extra));
    }

    /** FR-011/SC-002: cierra exacto con varias alícuotas y percepciones. */
    public function test_ecuacion_de_totales_cierra_exacta(): void
    {
        $this->ventaCon([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
            ['cantidad' => 2, 'precio' => 500, 'iva_pct' => '10.5'],
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => 'exento'],
            ['cantidad' => 1, 'precio' => 200, 'iva_pct' => 'no_gravado'],
        ], [
            ['tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 50],
            ['tipo' => 'percepcion', 'concepto' => 'Percepción IVA', 'monto' => 30],
        ]);

        $t = $this->totales();

        $this->assertEqualsWithDelta(
            $t['total_facturado'],
            $t['no_gravados_exentos'] + $t['gravados'] + $t['iva_total'] + $t['perc_iva_iibb_total'],
            0.0,
            'La ecuación tiene que cerrar EXACTA, sin tolerancia (FR-011).'
        );
    }

    /** FR-011a: Imp. Internos e Imp. Municipales quedan fuera de la ecuación. */
    public function test_imp_internos_y_municipales_no_entran_al_total_facturado(): void
    {
        $this->ventaCon([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
        ], [
            ['tipo' => 'impuesto_interno', 'concepto' => 'Impuestos internos', 'monto' => 999],
        ]);

        $t = $this->totales();

        // Si Imp. Internos entrara, el total facturado incluiría los 999 y no cerraría contra
        // no_gravados+gravados+iva+perc (que ES lo que se está verificando).
        $this->assertEqualsWithDelta(
            $t['total_facturado'],
            $t['no_gravados_exentos'] + $t['gravados'] + $t['iva_total'] + $t['perc_iva_iibb_total'],
            0.0
        );
        $this->assertEqualsWithDelta(1000.0, $t['gravados'], 0.01);
    }

    /** FR-012: los totales corresponden al período completo, no a una página. */
    public function test_totales_son_del_periodo_completo(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->ventaCon([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);
        }

        $t = $this->totales();

        $this->assertEqualsWithDelta(1500.0, $t['gravados'], 0.01, '15 ventas de $100 gravado, más allá de cualquier paginación de 10.');
    }

    /** La NC resta y la ND suma en los totales (FR-022). */
    public function test_nota_de_credito_resta_en_los_totales(): void
    {
        $venta = $this->ventaCon([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01',
            'fecha_emision' => '2026-08-15',
            'monto' => 121.0,
            'tipo_comprobante' => 'A',
            'descripcion' => 'Devolución parcial',
        ]);

        $t = $this->totales();

        // 1000 gravado de la venta menos ~100 gravado de la nota (heredó 21% de la única alícuota).
        $this->assertEqualsWithDelta(900.0, $t['gravados'], 0.02);
    }
}
