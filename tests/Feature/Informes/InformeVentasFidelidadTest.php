<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\InformeVentasDetalladoExport;
use App\Exports\Informes\InformeVentasExport;
use App\Models\DatosEmpresa;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 076 — invariantes que cruzan las cuatro salidas del Informe de Ventas (SC-004, FR-020).
 *
 * Se escriben en un archivo aparte porque no pertenecen a una sola clase de producción: I9 mira el
 * export resumen, I5c/SC-004 comparan pantalla, export resumen, export detallado y PDF entre sí.
 */
class InformeVentasFidelidadTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    /** I9 — el export resumen sigue con sus dos hojas después del cambio de columna (FR-020). */
    public function test_el_export_resumen_conserva_sus_dos_hojas(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        $hojas = (new InformeVentasExport(app(VentasInformeQuery::class), $this->request()))->sheets();

        $this->assertCount(2, $hojas);
        $this->assertSame('Informe de Ventas Resumen', $hojas[0]->title());
        $this->assertSame('Ventas', $hojas[1]->title());
        $this->assertNotSame($hojas[0]->title(), $hojas[1]->title());
    }

    /**
     * SC-004 — la misma línea trae el mismo importe en pantalla, export resumen y PDF. El export
     * detallado se suma a esta comparación en la Fase 4, cuando exista
     * `InformeVentasDetalladoExport`; hasta entonces esta parte del invariante ya cubre las tres
     * salidas que existen hoy.
     */
    public function test_la_misma_linea_trae_el_mismo_importe_en_pantalla_export_y_pdf(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 700, 'iva_pct' => '10.5'],
        ]);

        $request = $this->request();
        $informe = app(VentasInformeQuery::class);

        // Pantalla: el motor de datos crudo.
        $filasPantalla = $informe->detalle($request)->where('id', $venta->id)->orderBy('detalle.id')->get();

        // Export resumen: hoja plana, misma columna "Total Venta" en la última posición.
        $hojaPlana = (new InformeVentasExport($informe, $request))->sheets()[1]->array();
        $filasExport = array_values(array_filter($hojaPlana, fn ($fila) => $fila[0] === $venta->id));

        $this->assertCount(2, $filasPantalla);
        $this->assertCount(2, $filasExport);

        foreach ($filasPantalla as $i => $fila) {
            $this->assertEqualsWithDelta((float) $fila->total_venta, (float) $filasExport[$i][14], 0.01);
        }

        // PDF: misma vista y mismos datos que usa el controlador, renderizada directamente (sin
        // pasar por dompdf, que no hace falta para verificar qué campo usa la plantilla).
        $html = view('informes.pdf.ventas', [
            'empresa' => DatosEmpresa::instancia(),
            'rango' => $informe->rango($request),
            'kpis' => $informe->kpis($request),
            'filas' => $filasPantalla,
            'topeFilas' => 500,
        ])->render();

        foreach ($filasPantalla as $fila) {
            $importeFormateado = number_format((float) $fila->total_venta, 2, ',', '.');
            $this->assertStringContainsString($importeFormateado, $html);
        }
    }

    /** FR-021: los dos exports usan la sigla completa del comprobante, no la letra sola. */
    public function test_los_dos_exports_usan_la_sigla_completa_del_comprobante(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100]], ['tipo_comprobante' => 'B']);

        $request = $this->request();
        $informe = app(VentasInformeQuery::class);

        $hojaLegible = array_slice((new InformeVentasExport($informe, $request))->sheets()[0]->array(), 1);
        $hojaPlana = array_slice((new InformeVentasExport($informe, $request))->sheets()[1]->array(), 1);
        $detallado = array_slice((new InformeVentasDetalladoExport($informe, $request))->array(), 10);

        $filaLegible = array_values(array_filter($hojaLegible, fn ($f) => ($f[0] ?? null) === $venta->id))[0];
        $filaPlana = array_values(array_filter($hojaPlana, fn ($f) => ($f[0] ?? null) === $venta->id))[0];
        $filaDetallado = array_values(array_filter($detallado, fn ($f) => ($f[0] ?? null) === $venta->id))[0];

        $this->assertSame('FCB', $filaLegible[3]);
        $this->assertSame('FCB', $filaPlana[4]);
        $this->assertSame('FCB', $filaDetallado[8]);
    }
}
