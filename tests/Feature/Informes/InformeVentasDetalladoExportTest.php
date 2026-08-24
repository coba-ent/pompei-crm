<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\InformeVentasDetalladoExport;
use App\Models\ComprobanteFiscal;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * spec 076 US2 — el export detallado: 44 columnas comparables celda a celda con Contagram
 * (`contracts/export-detallado.md`).
 */
class InformeVentasDetalladoExportTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

    private const RÓTULOS = [
        'Id', 'Emisión', 'Vencimiento', 'Categoría', 'Cliente', 'CUIT / DNI', 'ARCA', 'Tipo',
        'Tipo de Comprobante', 'Punto de Venta', 'N° Factura', 'Vendedor', 'Producto/Servicio',
        'Código', 'Tipo', 'Proveedor', 'Cantidad', 'Precio Unitario', 'Costo Total Actual',
        'CMV Total', 'Lista de Precios', 'Precio de Venta', 'Resultado', 'Subtotal sin Descuento',
        'Descuento en $', 'Subtotal con Descuento', 'Importe Neto No Gravado',
        'Importe Neto Exento', 'Importe Neto Gravado', 'IVA - 2,5%', 'IVA - 5%', 'IVA - 10,5%',
        'IVA - 21%', 'IVA - 27%', 'Exento', 'No Gravado', 'Perc. IVA', 'Perc. IIBB',
        'Imp. Internos', 'Total Venta', 'Etiquetas', 'Nota para el Cliente', 'Nota Interna',
        'Afecta Stock',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function export(array $params = []): InformeVentasDetalladoExport
    {
        return new InformeVentasDetalladoExport(app(VentasInformeQuery::class), $this->request($params));
    }

    /** Una sola hoja, KPIs en las filas 1-8, encabezado en la fila 10 (contrato §2). */
    public function test_una_sola_hoja_con_kpis_en_1_8_y_encabezado_en_fila_10(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        $hoja = $this->export();
        $filas = $hoja->array();

        // Rótulos en una fila, valores en la de abajo (como el archivo real de Contagram) — no
        // rótulo-valor intercalados en la misma fila.
        $this->assertSame(['Total Ventas Creadas', 'Total Nota de Débito', 'Total Nota de Crédito', 'Total Ventas'], $filas[0]);
        $this->assertIsFloat($filas[1][0]);
        $this->assertIsFloat($filas[1][3]);
        // La fila en blanco entre bloques tiene que EXISTIR (índice 2 real), no desaparecer.
        $this->assertSame([], array_values(array_filter($filas[2], fn ($v) => $v !== null && $v !== '')));
        $this->assertSame(self::RÓTULOS, $filas[9]);
    }

    /** Las 44 columnas, rótulo y orden exactos, incluida la duplicación deliberada de "Tipo". */
    public function test_las_44_columnas_en_el_orden_del_contrato(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        $encabezado = $this->export()->array()[9];

        $this->assertCount(44, $encabezado);
        $this->assertSame(self::RÓTULOS, $encabezado);
        $this->assertSame('Tipo', $encabezado[7]);
        $this->assertSame('Tipo', $encabezado[14]);
    }

    /** I3: cada línea imputa a una sola columna de neto y a como mucho una de alícuota. */
    public function test_cada_linea_imputa_a_una_sola_columna_de_neto_y_alicuota(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 500, 'iva_pct' => '10.5'],
            ['cantidad' => 1, 'precio' => 300, 'iva_pct' => 'exento'],
            ['cantidad' => 1, 'precio' => 200, 'iva_pct' => 'no_gravado'],
        ]);

        $filas = $this->filasDeVenta($venta->id);

        // Índices dentro de la fila (0-based): 26 no_gravado, 27 exento, 28 gravado,
        // 29..33 alícuotas 2.5/5/10.5/21/27.
        foreach ($filas as $fila) {
            $netos = array_slice($fila, 26, 3);
            $noVacios = array_filter($netos, fn ($v) => (float) $v !== 0.0);
            $this->assertLessThanOrEqual(1, count($noVacios), 'Más de una columna de neto con valor: '.json_encode($fila));

            $alicuotas = array_slice($fila, 29, 5);
            $noVaciasAlicuota = array_filter($alicuotas, fn ($v) => (float) $v !== 0.0);
            $this->assertLessThanOrEqual(1, count($noVaciasAlicuota), 'Más de una alícuota con valor: '.json_encode($fila));
        }

        // La de 21% tiene neto gravado > 0 y su columna de IVA en 29+3=32... se verifica el total.
        $lineaGravada21 = collect($filas)->first(fn ($f) => (float) $f[28] > 0 && (float) $f[32] > 0);
        $this->assertNotNull($lineaGravada21);
    }

    /** I6: una venta con dos comprobantes fiscales (rechazo + reintento) aporta una fila por línea. */
    public function test_una_venta_con_rechazo_y_reintento_aporta_una_sola_fila(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'B',
            'numero' => 1,
            'estado' => 'rechazado',
            'motivo_rechazo' => 'Error de prueba',
        ]);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'B',
            'numero' => 2,
            'cae' => '12345678901234',
            'cae_vencimiento' => '2027-01-01',
            'estado' => 'aprobado',
        ]);

        $filas = $this->filasDeVenta($venta->id);

        $this->assertCount(1, $filas);
        $this->assertSame('Aprobado', $filas[0][6]);
    }

    /** Valores literales de las columnas nuevas cuando el dato no existe (spec.md Clarifications). */
    public function test_valores_literales_cuando_el_dato_no_existe(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        $fila = $this->filasDeVenta($venta->id)[0];

        $this->assertSame('---', $fila[6]); // ARCA
        $this->assertSame('-', $fila[9]); // Punto de Venta
        $this->assertSame('-', $fila[10]); // N° Factura
        $this->assertSame('', (string) ($fila[20] ?? '')); // Lista de Precios
    }

    /** I5: los totales del archivo coinciden con los KPIs de la pantalla para los mismos filtros. */
    public function test_los_totales_del_archivo_coinciden_con_los_kpis(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);
        $this->venta([['cantidad' => 2, 'precio' => 400, 'iva_pct' => '10.5']]);

        $informe = app(VentasInformeQuery::class);
        $kpis = $informe->kpis($this->request());

        $filas = array_slice($this->export()->array(), 10);
        $sumaTotalVenta = array_sum(array_column($filas, 39));

        $this->assertEqualsWithDelta($kpis['total_ventas'], round($sumaTotalVenta, 2), 0.01);
    }

    /** I7: las columnas del desglose impositivo sin valor van en cero, no vacías. */
    public function test_columnas_de_desglose_sin_valor_van_en_cero(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']]);

        $fila = $this->filasDeVenta($venta->id)[0];

        // No gravado (26), exento (27), y las 4 alícuotas que no son 21% (29,30,31,33).
        foreach ([26, 27, 29, 30, 31, 33] as $indice) {
            $this->assertSame(0.0, (float) $fila[$indice], "índice {$indice} debería ser 0.0, no vacío");
            $this->assertNotNull($fila[$indice], "índice {$indice} no puede ser NULL");
        }
    }

    /**
     * I8: las fechas van como fecha de Excel, no como texto. Acá se verifica el valor que produce
     * `array()` (serial numérico de Excel, ver `fechaExcel()`); el round-trip completo contra el
     * archivo REAL escrito está en `test_el_archivo_real_tiene_el_encabezado_en_la_fila_10_y_fechas_como_excel()`,
     * que es el que de verdad prueba que Excel lo va a mostrar como fecha y no como número pelado.
     */
    public function test_las_fechas_van_como_fecha_de_excel(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']], ['fecha_emision' => '2026-03-15']);

        $fila = $this->filasDeVenta($venta->id, ['desde' => '2026-03-01', 'hasta' => '2026-03-31'])[0];

        $this->assertIsFloat($fila[1]);
        $this->assertEqualsWithDelta(
            \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable('2026-03-15')),
            $fila[1],
            0.001
        );
    }

    /** Una línea con condición de IVA nula, vacía o no reconocida imputa a Importe Neto No Gravado. */
    public function test_iva_no_reconocido_imputa_a_neto_no_gravado(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 500, 'iva_pct' => 'una_cadena_rara'],
        ]);

        $fila = $this->filasDeVenta($venta->id)[0];

        $this->assertEqualsWithDelta(500.0, (float) $fila[26], 0.01); // Importe Neto No Gravado
        $this->assertEqualsWithDelta(0.0, (float) $fila[27], 0.01); // Exento
        $this->assertEqualsWithDelta(0.0, (float) $fila[28], 0.01); // Gravado
    }

    /** @return list<list<mixed>> */
    private function filasDeVenta(int $ventaId, array $params = []): array
    {
        $todas = array_slice($this->export($params)->array(), 10);

        return array_values(array_filter($todas, fn ($f) => (int) $f[0] === $ventaId));
    }

    /**
     * Round-trip real: escribe el .xlsx de verdad (no el array de PHP) y lo vuelve a leer con
     * PhpSpreadsheet. Hace falta esto y no alcanza con inspeccionar `array()`: Maatwebsite
     * aplana las filas con `Collection::flatMap()` antes de escribirlas, y un `flatMap` sobre una
     * fila `[]` (array vacío) la hace desaparecer en vez de dejarla en blanco — el array de PHP
     * puede tener la fila en blanco en el índice correcto y el archivo real igual salir corrido.
     * Este es el test que hubiera atrapado ese bug (spec 076, hallazgo post-deploy 24/08/2026).
     */
    public function test_el_archivo_real_tiene_el_encabezado_en_la_fila_10_y_fechas_como_excel(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']], ['fecha_emision' => '2026-03-15']);

        $params = ['desde' => '2026-03-01', 'hasta' => '2026-03-31'];
        $path = 'test-detallado-'.uniqid().'.xlsx';
        Excel::store($this->export($params), $path, 'local');

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(storage_path('app/private/'.$path));
        $sheet = $spreadsheet->getActiveSheet();

        try {
            $this->assertSame('Id', $sheet->getCell('A10')->getValue());
            $this->assertSame('Emisión', $sheet->getCell('B10')->getValue());
            $this->assertTrue($sheet->getStyle('A10')->getFont()->getBold());

            // Fila 11: primer dato. El id de la venta tiene que estar ahí, no corrido.
            $this->assertEquals($venta->id, $sheet->getCell('A11')->getValue());

            // La fecha tiene que ser numérica (serial de Excel), no un string.
            $fechaCelda = $sheet->getCell('B11')->getValue();
            $this->assertIsNumeric($fechaCelda);
            $this->assertTrue(\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($sheet->getCell('B11')));
        } finally {
            @unlink(storage_path('app/private/'.$path));
        }
    }
}
