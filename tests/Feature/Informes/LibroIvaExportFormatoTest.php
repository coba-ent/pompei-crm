<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\LibroIvaExport;
use App\Models\Cliente;
use App\Models\DatosEmpresa;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;

/**
 * spec 089 — formato visual del Excel del Libro IVA.
 *
 * Todos los asserts se hacen sobre el `.xlsx` **generado y releído** con PhpSpreadsheet, nunca sobre
 * el array de PHP previo a escribirlo: el array no dice nada del formato, y el paquete tiene gotchas
 * conocidos entre lo que se le pasa y lo que termina en el archivo (memoria del proyecto).
 */
class LibroIvaExportFormatoTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    // -----------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------

    /** Genera el export real a un archivo temporal y devuelve la hoja ya releída. */
    private function hojaGenerada(string $titulo = 'Libro IVA Ventas', array $params = []): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $request = Request::create('/', 'POST', array_merge(
            ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true],
            $params
        ));

        $query = $titulo === 'Libro IVA Compras'
            ? app(LibroIvaComprasQuery::class)
            : app(LibroIvaVentasQuery::class);

        $bytes = Excel::raw(new LibroIvaExport($query, $request, $titulo), \Maatwebsite\Excel\Excel::XLSX);

        $ruta = tempnam(sys_get_temp_dir(), 'libro_iva_').'.xlsx';
        file_put_contents($ruta, $bytes);

        $hoja = IOFactory::load($ruta)->getActiveSheet();

        @unlink($ruta);

        return $hoja;
    }

    /** Una venta con una alícuota al 21%, en el período de prueba. */
    private function ventaConIva(array $overrides = []): Venta
    {
        $venta = Venta::factory()->create(array_merge([
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => '2026-08-10',
            'tipo_comprobante' => 'B',
            'total' => 1210,
        ], $overrides));

        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        return $venta;
    }

    /** Fila (base 1) donde arrancan los datos, según el layout de la spec 089. */
    private const FILA_TITULOS = 5;

    private const FILA_PRIMER_DATO = 6;

    // -----------------------------------------------------------------------------------
    // T001 — No-regresión de contenido (el test que protege lo verificado peso por peso)
    // -----------------------------------------------------------------------------------

    /**
     * El formato puede cambiar; los números no. Este test fija los valores de las filas de datos
     * —valor por valor, en su columna— para que correr las filas hacia abajo por el encabezado no
     * pueda desalinear en silencio los importes ya verificados contra Contagram (specs 077/088).
     */
    public function test_no_regresion_las_filas_de_datos_conservan_sus_valores_y_columnas(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Uno', 'cuit' => '20111111112']);
        $venta = $this->ventaConIva(['cliente_id' => $cliente->id, 'nro_comprobante' => '0001-00000042']);

        $hoja = $this->hojaGenerada();

        $fila = self::FILA_PRIMER_DATO;

        // Las 19 columnas del contrato de la spec 077, en su orden, con los valores de la venta.
        $this->assertSame($venta->id, (int) $hoja->getCell('A'.$fila)->getValue(), 'columna Id');
        $this->assertSame('B', $hoja->getCell('C'.$fila)->getValue(), 'columna Tipo');
        $this->assertSame('0001-00000042', (string) $hoja->getCell('D'.$fila)->getValue(), 'columna N° de Comprobante');
        $this->assertSame('Cliente Uno', $hoja->getCell('E'.$fila)->getValue(), 'columna Cliente/Proveedor');
        $this->assertSame('20111111112', (string) $hoja->getCell('F'.$fila)->getValue(), 'columna CUIT/DNI');

        $this->assertEqualsWithDelta(0, (float) $hoja->getCell('H'.$fila)->getValue(), 0.001, 'Neto No Gravado');
        $this->assertEqualsWithDelta(0, (float) $hoja->getCell('I'.$fila)->getValue(), 0.001, 'Neto Exento');
        $this->assertEqualsWithDelta(1000, (float) $hoja->getCell('J'.$fila)->getValue(), 0.001, 'Neto Gravado');
        $this->assertEqualsWithDelta(210, (float) $hoja->getCell('N'.$fila)->getValue(), 0.001, 'IVA 21%');

        // Las columnas de alícuota que no aplican van en 0, no vacías — es lo que garantiza
        // WithStrictNullComparison (sin él, `0 == null` las borraría del archivo).
        foreach (['K', 'L', 'M', 'O'] as $col) {
            $this->assertSame(0.0, (float) $hoja->getCell($col.$fila)->getValue(), "columna {$col} (alícuota sin uso) debe ser 0, no vacía");
        }
    }

    /** Las 19 columnas siguen presentes y en el mismo orden (FR-010). */
    public function test_no_regresion_las_19_columnas_conservan_nombre_y_orden(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $esperados = [
            'Id', 'Emisión', 'Tipo', 'N° de Comprobante', 'Cliente/Proveedor', 'CUIT/DNI', 'Condición de IVA',
            'Importe Neto No Gravado', 'Importe Neto Exento', 'Importe Neto Gravado',
            'IVA 2,5%', 'IVA 5%', 'IVA 10,5%', 'IVA 21%', 'IVA 27%',
            'Perc. IVA', 'Perc. IIBB', 'Imp. Internos', 'Imp. Municipales',
        ];

        foreach ($esperados as $i => $esperado) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $this->assertSame(
                $esperado,
                $hoja->getCell($col.self::FILA_TITULOS)->getValue(),
                "columna {$col} de títulos"
            );
        }
    }

    // -----------------------------------------------------------------------------------
    // US1 — Encabezado del negocio
    // -----------------------------------------------------------------------------------

    public function test_encabezado_trae_razon_social_cuit_titulo_y_periodo(): void
    {
        DatosEmpresa::create(['razon_social' => 'Pompei Sanitarios', 'cuit' => '20273351249']);
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertStringContainsString('Pompei Sanitarios', (string) $hoja->getCell('A1')->getValue());
        $this->assertStringContainsString('20273351249', (string) $hoja->getCell('A2')->getValue());
        $this->assertSame('Libro IVA Ventas', $hoja->getCell('F2')->getValue());
        $this->assertSame('Periodo: Agosto de 2026', $hoja->getCell('F3')->getValue());
    }

    public function test_encabezado_de_compras_dice_libro_iva_compras(): void
    {
        DatosEmpresa::create(['razon_social' => 'Pompei Sanitarios', 'cuit' => '20273351249']);

        $hoja = $this->hojaGenerada('Libro IVA Compras');

        $this->assertSame('Libro IVA Compras', $hoja->getCell('F2')->getValue());
    }

    /** FR-004: sin datos de empresa cargados el archivo se genera igual, con esos renglones vacíos. */
    public function test_sin_datos_de_empresa_el_archivo_se_genera_igual(): void
    {
        $this->assertNull(DatosEmpresa::instancia());
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertSame('', (string) $hoja->getCell('A1')->getValue());
        // El título y el período no dependen de la empresa: siguen estando.
        $this->assertSame('Libro IVA Ventas', $hoja->getCell('F2')->getValue());
        $this->assertSame('Periodo: Agosto de 2026', $hoja->getCell('F3')->getValue());
    }

    // -----------------------------------------------------------------------------------
    // US2 — Formato de la tabla
    // -----------------------------------------------------------------------------------

    /** SC-002: los importes son NÚMEROS, no texto — es lo que permite sumarlos en Excel. */
    public function test_los_importes_son_numeros_y_las_fechas_son_fechas(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();
        $fila = self::FILA_PRIMER_DATO;

        $neto = $hoja->getCell('J'.$fila)->getValue();
        $this->assertIsNumeric($neto, 'el neto gravado debe ser numérico, no string');

        // La fecha se graba como serial de Excel y se muestra con formato DD/MM/YYYY (FR-006).
        $emision = $hoja->getCell('B'.$fila)->getValue();
        $this->assertIsNumeric($emision, 'la emisión debe ser un valor de fecha de Excel, no un string');
        $this->assertSame('2026-08-10', ExcelDate::excelToDateTimeObject($emision)->format('Y-m-d'));
        $this->assertStringContainsString('DD/MM/YYYY', $hoja->getStyle('B'.$fila)->getNumberFormat()->getFormatCode());
    }

    /** FR-007: importes con dos decimales y negativos entre paréntesis. */
    public function test_los_importes_tienen_formato_de_dos_decimales(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertSame('0.00;(0.00)', $hoja->getStyle('J'.self::FILA_PRIMER_DATO)->getNumberFormat()->getFormatCode());
    }

    /** FR-005: fila de títulos con fondo azul y texto blanco. */
    public function test_la_fila_de_titulos_tiene_fondo_azul_y_texto_blanco(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();
        $estilo = $hoja->getStyle('A'.self::FILA_TITULOS);

        $this->assertSame('FF0E5DA1', $estilo->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFFFFFF', $estilo->getFont()->getColor()->getARGB());
        $this->assertSame('center', $estilo->getAlignment()->getHorizontal());
    }

    /** FR-009: tipografía uniforme en todo el documento. */
    public function test_el_documento_usa_arial(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertSame('Arial', $hoja->getStyle('A1')->getFont()->getName());
        $this->assertSame('Arial', $hoja->getStyle('A'.self::FILA_PRIMER_DATO)->getFont()->getName());
    }

    /** FR-008: los anchos de columna están fijados, no quedan en el default. */
    public function test_las_columnas_tienen_ancho_definido(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        foreach (['A', 'B', 'E', 'J'] as $col) {
            $this->assertGreaterThan(0, $hoja->getColumnDimension($col)->getWidth(), "ancho de la columna {$col}");
        }
    }

    /**
     * FR-016 y grilla oculta, como el fixture de Contagram.
     *
     * La grilla se verifica sobre el **XML del archivo**, no releyendo con `IOFactory`: el reader
     * parsea `showGridLines="false"` como el string `'false'` (que en PHP es *truthy*) y devuelve
     * `true`, aunque el archivo esté correcto. El XML es la fuente de verdad de lo que abre Excel.
     */
    public function test_la_hoja_es_apaisada_y_sin_grilla(): void
    {
        $this->ventaConIva();

        $this->assertSame('landscape', $this->hojaGenerada()->getPageSetup()->getOrientation());

        $xml = $this->xmlDeLaHoja();
        $this->assertMatchesRegularExpression('/<sheetView[^>]*showGridLines="(false|0)"/', $xml);
    }

    /** XML crudo de la primera hoja del archivo generado. */
    private function xmlDeLaHoja(string $titulo = 'Libro IVA Ventas'): string
    {
        $request = Request::create('/', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
        $bytes = Excel::raw(
            new LibroIvaExport(app(LibroIvaVentasQuery::class), $request, $titulo),
            \Maatwebsite\Excel\Excel::XLSX
        );

        $ruta = tempnam(sys_get_temp_dir(), 'libro_iva_xml_').'.xlsx';
        file_put_contents($ruta, $bytes);

        $zip = new \ZipArchive;
        $zip->open($ruta);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($ruta);

        return $xml;
    }

    /** Los acentos llegan bien al archivo (regresión de encoding — ver T011, falso positivo). */
    public function test_los_titulos_conservan_los_acentos(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertSame('Emisión', $hoja->getCell('B'.self::FILA_TITULOS)->getValue());
        $this->assertSame('Condición de IVA', $hoja->getCell('G'.self::FILA_TITULOS)->getValue());
    }

    // -----------------------------------------------------------------------------------
    // US3 — Totales al pie
    // -----------------------------------------------------------------------------------

    /** FR-011/FR-013: tres renglones al pie, y facturación + notas = totales. */
    public function test_el_pie_trae_los_tres_renglones_de_totales_y_cierran(): void
    {
        $venta = $this->ventaConIva();
        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'tipo_comprobante' => 'B',
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-15',
            'monto' => 605, 'nro_comprobante' => '0001-00000009',
        ]);

        $hoja = $this->hojaGenerada();
        $ultima = $hoja->getHighestRow();

        // Las 3 filas de totales son las últimas del archivo.
        // Los rótulos van en G, la última columna antes de los importes.
        $this->assertSame('Por Facturación:', $hoja->getCell('G'.($ultima - 2))->getValue());
        $this->assertSame('Por Nota de Crédito:', $hoja->getCell('G'.($ultima - 1))->getValue());
        $this->assertSame('Totales:', $hoja->getCell('G'.$ultima)->getValue());

        // FR-013: en cada columna de importe, facturación + notas = total.
        foreach (['J', 'N'] as $col) {
            $facturacion = (float) $hoja->getCell($col.($ultima - 2))->getValue();
            $notas = (float) $hoja->getCell($col.($ultima - 1))->getValue();
            $total = (float) $hoja->getCell($col.$ultima)->getValue();

            $this->assertEqualsWithDelta($facturacion + $notas, $total, 0.01, "la columna {$col} no cierra");
        }
    }

    /** FR-012: el renglón de totales se destaca (negrita). */
    public function test_el_renglon_de_totales_esta_en_negrita(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();
        $ultima = $hoja->getHighestRow();

        $this->assertTrue($hoja->getStyle('J'.$ultima)->getFont()->getBold(), 'los importes del total van en negrita');
        $this->assertFalse($hoja->getStyle('J'.($ultima - 2))->getFont()->getBold(), 'los de facturación, no');
    }

    /** Un período sin notas de crédito deja ese renglón en cero. */
    public function test_periodo_sin_notas_deja_el_renglon_de_notas_en_cero(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();
        $ultima = $hoja->getHighestRow();

        $this->assertSame(0.0, (float) $hoja->getCell('J'.($ultima - 1))->getValue());
        $this->assertEqualsWithDelta(
            (float) $hoja->getCell('J'.($ultima - 2))->getValue(),
            (float) $hoja->getCell('J'.$ultima)->getValue(),
            0.01
        );
    }

    /** FR-015: la barra de KPIs ya no se emite arriba de la tabla. */
    public function test_ya_no_se_emiten_los_totales_arriba_de_la_tabla(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        // En el layout nuevo, la fila 5 son los títulos de columna; el viejo bloque de KPIs
        // ("No Gravados/Exentos" en A3) no debe estar en ningún lado del encabezado.
        foreach (range(1, 4) as $f) {
            $this->assertNotSame('No Gravados/Exentos', $hoja->getCell('A'.$f)->getValue());
        }
    }

    // -----------------------------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------------------------

    /** Período vacío: encabezado, títulos y totales en cero, sin excepción. */
    public function test_periodo_vacio_genera_el_archivo_igual(): void
    {
        DatosEmpresa::create(['razon_social' => 'Pompei Sanitarios', 'cuit' => '20273351249']);

        $hoja = $this->hojaGenerada('Libro IVA Ventas', ['mes' => 1, 'anio' => 2026]);

        $this->assertSame('Libro IVA Ventas', $hoja->getCell('F2')->getValue());
        $this->assertSame('Periodo: Enero de 2026', $hoja->getCell('F3')->getValue());
        $this->assertSame('Id', $hoja->getCell('A'.self::FILA_TITULOS)->getValue());

        $ultima = $hoja->getHighestRow();
        $this->assertSame('Totales:', $hoja->getCell('G'.$ultima)->getValue());
        $this->assertSame(0.0, (float) $hoja->getCell('J'.$ultima)->getValue());
    }

    /** SC-005: el mismo formato aplica al Libro IVA Compras. */
    public function test_compras_recibe_el_mismo_formato(): void
    {
        $hoja = $this->hojaGenerada('Libro IVA Compras');

        $this->assertSame('FF0E5DA1', $hoja->getStyle('A'.self::FILA_TITULOS)->getFill()->getStartColor()->getARGB());
        $this->assertSame('Arial', $hoja->getStyle('A'.self::FILA_TITULOS)->getFont()->getName());
        $this->assertSame('landscape', $hoja->getPageSetup()->getOrientation());
    }
}
