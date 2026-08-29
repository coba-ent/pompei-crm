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

        // Las 13 columnas de Contagram (spec 091), en su orden, con los valores de la venta.
        $this->assertSame('B', $hoja->getCell('B'.$fila)->getValue(), 'columna Tipo');
        $this->assertSame('0001-00000042', (string) $hoja->getCell('C'.$fila)->getValue(), 'columna N° de Comprobante');
        $this->assertSame('Cliente Uno', $hoja->getCell('D'.$fila)->getValue(), 'columna Razón Social');
        $this->assertSame('20111111112', (string) $hoja->getCell('E'.$fila)->getValue(), 'columna CUIT / DNI');

        $this->assertEqualsWithDelta(0, (float) $hoja->getCell('G'.$fila)->getValue(), 0.001, 'Neto No Grav.');
        $this->assertEqualsWithDelta(0, (float) $hoja->getCell('H'.$fila)->getValue(), 0.001, 'Neto Exento');
        $this->assertEqualsWithDelta(1000, (float) $hoja->getCell('I'.$fila)->getValue(), 0.001, 'Neto Grav.');
        $this->assertEqualsWithDelta(210, (float) $hoja->getCell('J'.$fila)->getValue(), 0.001, 'IVA');
        $this->assertEqualsWithDelta(1210, (float) $hoja->getCell('K'.$fila)->getValue(), 0.001, 'Total Facturado');

        // Los netos en cero se escriben igual, no quedan vacíos — lo garantiza
        // WithStrictNullComparison (sin él, `0 == null` los borraría del archivo).
        foreach (['G', 'H'] as $col) {
            $this->assertSame(0.0, (float) $hoja->getCell($col.$fila)->getValue(), "columna {$col} en cero debe escribirse, no quedar vacía");
        }
    }

    /** Las 13 columnas de Contagram, en su orden (spec 091, FR-001). */
    public function test_las_13_columnas_calcan_las_de_contagram(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $esperados = [
            'Fecha', 'Tipo', 'N° de Comprobante', 'Razón Social', 'CUIT / DNI', 'Condición de IVA',
            'Neto No Grav.', 'Neto Exento', 'Neto Grav.', 'IVA 21%', 'Total Facturado',
            'Provincia', 'Medio de Cobro',
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

        $neto = $hoja->getCell('I'.$fila)->getValue();
        $this->assertIsNumeric($neto, 'el neto gravado debe ser numérico, no string');

        // La fecha se graba como serial de Excel y se muestra con formato DD/MM/YYYY (FR-006).
        $emision = $hoja->getCell('A'.$fila)->getValue();
        $this->assertIsNumeric($emision, 'la emisión debe ser un valor de fecha de Excel, no un string');
        $this->assertSame('2026-08-10', ExcelDate::excelToDateTimeObject($emision)->format('Y-m-d'));
        $this->assertStringContainsString('DD/MM/YYYY', $hoja->getStyle('A'.$fila)->getNumberFormat()->getFormatCode());
    }

    /** FR-007: importes con dos decimales y negativos entre paréntesis. */
    public function test_los_importes_tienen_formato_de_dos_decimales(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();

        $this->assertSame('0.00;(0.00)', $hoja->getStyle('I'.self::FILA_PRIMER_DATO)->getNumberFormat()->getFormatCode());
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

        $this->assertSame('Razón Social', $hoja->getCell('D'.self::FILA_TITULOS)->getValue());
        $this->assertSame('Condición de IVA', $hoja->getCell('F'.self::FILA_TITULOS)->getValue());
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
        $this->assertSame('Por Facturación:', $hoja->getCell('F'.($ultima - 2))->getValue());
        $this->assertSame('Por Nota de Crédito:', $hoja->getCell('F'.($ultima - 1))->getValue());
        $this->assertSame('Totales:', $hoja->getCell('F'.$ultima)->getValue());

        // FR-013: en cada columna de importe, facturación + notas = total.
        foreach (['I', 'J', 'K'] as $col) {
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

        $this->assertTrue($hoja->getStyle('I'.$ultima)->getFont()->getBold(), 'los importes del total van en negrita');
        $this->assertFalse($hoja->getStyle('I'.($ultima - 2))->getFont()->getBold(), 'los de facturación, no');
    }

    /** Un período sin notas de crédito deja ese renglón en cero. */
    public function test_periodo_sin_notas_deja_el_renglon_de_notas_en_cero(): void
    {
        $this->ventaConIva();

        $hoja = $this->hojaGenerada();
        $ultima = $hoja->getHighestRow();

        $this->assertSame(0.0, (float) $hoja->getCell('I'.($ultima - 1))->getValue());
        $this->assertEqualsWithDelta(
            (float) $hoja->getCell('I'.($ultima - 2))->getValue(),
            (float) $hoja->getCell('I'.$ultima)->getValue(),
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
        $this->assertSame('Fecha', $hoja->getCell('A'.self::FILA_TITULOS)->getValue());

        $ultima = $hoja->getHighestRow();
        $this->assertSame('Totales:', $hoja->getCell('F'.$ultima)->getValue());
        $this->assertSame(0.0, (float) $hoja->getCell('I'.$ultima)->getValue());
    }

    /** SC-005: el mismo formato aplica al Libro IVA Compras. */
    public function test_compras_recibe_el_mismo_formato(): void
    {
        $hoja = $this->hojaGenerada('Libro IVA Compras');

        $this->assertSame('FF0E5DA1', $hoja->getStyle('A'.self::FILA_TITULOS)->getFill()->getStartColor()->getARGB());
        $this->assertSame('Arial', $hoja->getStyle('A'.self::FILA_TITULOS)->getFont()->getName());
        $this->assertSame('landscape', $hoja->getPageSetup()->getOrientation());
    }

    // -----------------------------------------------------------------------------------
    // spec 091 — las 13 columnas de Contagram
    // -----------------------------------------------------------------------------------

    /**
     * FR-008 / SC-003 — **el test crítico de la spec 091**. La columna se rotula "IVA 21%" calcando a
     * Contagram, pero lleva el IVA **total**: si mirara sólo el tramo del 21%, una venta a otra
     * alícuota desaparecería del libro sin que nada lo indique (subdeclaración silenciosa).
     */
    public function test_una_venta_a_otra_alicuota_no_desaparece_del_libro(): void
    {
        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => '2026-08-10',
            'tipo_comprobante' => 'B',
            'total' => 1105,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem al 10,5%', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '10.5', 'subtotal' => 1000, 'subtotal_con_iva' => 1105,
        ]);

        $hoja = $this->hojaGenerada();
        $fila = self::FILA_PRIMER_DATO;

        $this->assertEqualsWithDelta(105, (float) $hoja->getCell('J'.$fila)->getValue(), 0.01,
            'el IVA al 10,5% debe aparecer en la columna de IVA, no en cero');
        $this->assertEqualsWithDelta(1105, (float) $hoja->getCell('K'.$fila)->getValue(), 0.01,
            'el Total Facturado debe incluir ese IVA');
    }

    /**
     * SC-003: con alícuotas mixtas, la suma de la columna de IVA es el IVA total del período.
     *
     * Se usa **Marzo** y no Agosto: la suite carga por migración los 14 comprobantes históricos de
     * agosto (spec 088), que sumarían su propio IVA al total y harían el assert dependiente de esos
     * datos en vez de los de este test.
     */
    public function test_la_columna_de_iva_suma_todas_las_alicuotas_del_periodo(): void
    {
        $una = Venta::factory()->create([
            'cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-10',
            'tipo_comprobante' => 'B', 'nro_comprobante' => '0001-00000301', 'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $una->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        $otra = Venta::factory()->create([
            'cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-11',
            'tipo_comprobante' => 'B', 'nro_comprobante' => '0001-00000302', 'total' => 1105,
        ]);
        VentaItem::create([
            'venta_id' => $otra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '10.5', 'subtotal' => 1000, 'subtotal_con_iva' => 1105,
        ]);

        $hoja = $this->hojaGenerada('Libro IVA Ventas', ['mes' => 3]);
        $ultima = $hoja->getHighestRow();

        // Fila de "Totales:" al pie — 210 al 21% + 105 al 10,5%.
        $this->assertEqualsWithDelta(315, (float) $hoja->getCell('J'.$ultima)->getValue(), 0.01);
    }

    /** FR-003: Total Facturado incluye las percepciones, que perdieron su columna propia. */
    public function test_total_facturado_incluye_las_percepciones(): void
    {
        $venta = $this->ventaConIva();
        \App\Models\VentaConcepto::create([
            'venta_id' => $venta->id, 'tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 50,
        ]);

        $hoja = $this->hojaGenerada();

        // 1000 neto + 210 IVA + 50 percepción.
        $this->assertEqualsWithDelta(1260, (float) $hoja->getCell('K'.self::FILA_PRIMER_DATO)->getValue(), 0.01);
    }

    /** FR-004: provincia fiscal, con respaldo en la comercial, y guion cuando no hay ninguna. */
    public function test_columna_provincia(): void
    {
        $cliente = Cliente::factory()->create(['provincia' => 'Buenos Aires', 'provincia_fiscal' => 'C.A.B.A.']);
        $this->ventaConIva(['cliente_id' => $cliente->id, 'nro_comprobante' => '0001-00000101']);

        $sinProvincia = Cliente::factory()->create(['provincia' => null, 'provincia_fiscal' => null]);
        $this->ventaConIva(['cliente_id' => $sinProvincia->id, 'fecha_emision' => '2026-08-11', 'nro_comprobante' => '0001-00000102']);

        $hoja = $this->hojaGenerada();

        $this->assertSame('C.A.B.A.', $hoja->getCell('L'.self::FILA_PRIMER_DATO)->getValue(),
            'la fiscal tiene precedencia sobre la comercial');
        $this->assertSame('-', $hoja->getCell('L'.(self::FILA_PRIMER_DATO + 1))->getValue(),
            'sin provincia va el guion, como Contagram');
    }

    /** FR-005: medio de cobro del comprobante; vacío si no fue cobrado. */
    public function test_columna_medio_de_cobro(): void
    {
        $cuenta = \App\Models\CuentaTesoreria::factory()->create(['nombre' => 'Mercado Pago']);
        $venta = $this->ventaConIva(['nro_comprobante' => '0001-00000201']);
        \App\Models\Cobro::create([
            'venta_id' => $venta->id, 'fecha' => '2026-08-10',
            'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 1210,
        ]);

        $this->ventaConIva(['fecha_emision' => '2026-08-11', 'nro_comprobante' => '0001-00000202']);  // sin cobro

        $hoja = $this->hojaGenerada();

        $this->assertSame('Mercado Pago', $hoja->getCell('M'.self::FILA_PRIMER_DATO)->getValue());
        $this->assertSame('', (string) $hoja->getCell('M'.(self::FILA_PRIMER_DATO + 1))->getValue(),
            'un comprobante sin cobrar deja la columna vacía');
    }

    /** FR-006: en Compras la última columna se rotula "Medio de Pago". */
    public function test_compras_rotula_medio_de_pago(): void
    {
        $hoja = $this->hojaGenerada('Libro IVA Compras');

        $this->assertSame('Medio de Pago', $hoja->getCell('M'.self::FILA_TITULOS)->getValue());
    }
}
