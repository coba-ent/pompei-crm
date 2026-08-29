<?php

namespace App\Exports\Informes;

use App\Models\DatosEmpresa;
use App\Services\Informes\Contador\DatosComercialesComprobante;
use App\Services\Informes\Contador\Periodo;
use App\Services\Informes\LibroIvaQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del Libro IVA (specs 077 y 089): **una hoja**, con el encabezado del negocio arriba, las 19
 * columnas del contrato (FR-034 de la 077) y los totales desglosados al pie.
 *
 * Reusa el mismo servicio de query que `data`/`stats`, así que los importes del archivo coinciden
 * al centavo con los de la pantalla (FR-033).
 *
 * ## Sobre el formato (spec 089)
 *
 * El formato calca el Excel real que exporta Contagram, guardado como fixture en
 * `tests/Fixtures/LibroIvaExport/`. Lo que **no** se calca es la lista de columnas: Contagram emite 13
 * con una sola columna de IVA, acá van las 19 de la spec 077 con las cinco alícuotas discriminadas.
 *
 * `WithStrictNullComparison` no es opcional: sin él PhpSpreadsheet compara cada celda contra `null`
 * con `==`, y como en PHP `0 == null`, las columnas de alícuota que no aplican —la mayoría en cualquier
 * comprobante— no se escribirían en el archivo.
 */
class LibroIvaExport implements FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * Las 13 columnas del Excel de Contagram, en su orden (spec 091, FR-001). El rótulo de la última
     * cambia según el libro ("Medio de Cobro" / "Medio de Pago", FR-006).
     */
    private const ENCABEZADOS = [
        'Fecha', 'Tipo', 'N° de Comprobante', 'Razón Social', 'CUIT / DNI', 'Condición de IVA',
        'Neto No Grav.', 'Neto Exento', 'Neto Grav.', 'IVA 21%', 'Total Facturado',
        'Provincia', 'Medio de Cobro',
    ];

    /** Azul corporativo de Contagram, ya usado en `Tesoreria\MovimientosExport`. */
    private const AZUL_ENCABEZADO = '0E5DA1';

    private const ULTIMA_COLUMNA = 'M';

    /** Fila (base 1) de los títulos de columna. Fija: el encabezado del negocio ocupa 1-4. */
    private const FILA_TITULOS = 5;

    /** Columnas de importe: los 3 netos, el IVA y el Total Facturado. */
    private const COLUMNAS_IMPORTE = ['G', 'H', 'I', 'J', 'K'];

    /** Se completan al armar el array; `styles()` los necesita porque el largo es variable. */
    private int $ultimaFilaDatos = 0;

    private int $filaPorFacturacion = 0;

    private int $filaPorNotas = 0;

    private int $filaTotales = 0;

    public function __construct(
        private LibroIvaQuery $informe,
        private Request $request,
        private string $titulo,
    ) {}

    public function array(): array
    {
        $empresa = DatosEmpresa::instancia();

        // Las filas del encabezado se arman con TODAS las posiciones (`null` incluido) hasta la
        // columna del título: `FromArray` escribe los valores por posición secuencial, así que un
        // array disperso (`[0 => x, 5 => y]`) no deja el segundo valor en la columna F.
        $filas = [
            $this->filaEncabezado(0, $empresa?->razon_social ? 'Razón Social: '.$empresa->razon_social : ''),
            $this->filaEncabezado(0, $empresa?->cuit ? 'N° C.U.I.T.: '.$empresa->cuit : '', 5, $this->titulo),
            $this->filaEncabezado(5, 'Periodo: '.$this->textoPeriodo()),
            [null],
            $this->encabezados(),
        ];

        $detalle = $this->informe->detalle($this->request)->get();
        $comerciales = (new DatosComercialesComprobante)->resolver($detalle, $this->esCompras());

        $acumulado = ['facturacion' => $this->ceros(), 'notas' => $this->ceros()];

        foreach ($detalle as $fila) {
            // FR-008: la columna rotulada "IVA 21%" (calcada de Contagram) lleva el IVA **total** del
            // comprobante, no sólo el tramo del 21%. Hoy el negocio factura sólo al 21%, pero si
            // apareciera una venta a otra alícuota su IVA desaparecería del libro — subdeclaración
            // silenciosa, justo lo que el principio III de la constitución prohíbe.
            $ivaTotal = (float) $fila->iva_2_5 + (float) $fila->iva_5 + (float) $fila->iva_10_5
                + (float) $fila->iva_21 + (float) $fila->iva_27;

            // Decisión 2 del plan: percepciones e impuestos pierden su columna propia, así que entran
            // en el Total Facturado para que sus importes no desaparezcan del archivo.
            $extras = (float) $fila->perc_iva + (float) $fila->perc_iibb
                + (float) $fila->imp_internos + (float) $fila->imp_municipales;

            $importes = [
                (float) $fila->neto_no_gravado,
                (float) $fila->neto_exento,
                (float) $fila->neto_gravado,
                $ivaTotal,
                (float) $fila->neto_no_gravado + (float) $fila->neto_exento + (float) $fila->neto_gravado + $ivaTotal + $extras,
            ];

            $datos = $comerciales->get($this->claveComercial($fila), ['provincia' => '-', 'medio' => '']);

            $filas[] = array_merge([
                $this->fechaExcel((string) $fila->emision),
                $fila->tipo,
                $fila->nro_comprobante,
                $fila->contraparte,
                $fila->cuit,
                $fila->condicion_iva,
            ], $importes, [$datos['provincia'], $datos['medio']]);

            // El desglose del pie se acumula acá, sobre las filas ya materializadas: son los mismos
            // números de `detalle()`, agrupados distinto. No se toca `LibroIvaQuery::totales()`, que
            // alimenta la barra de la pantalla y está verificada peso por peso contra Contagram.
            $grupo = $this->esNota((string) $fila->tipo) ? 'notas' : 'facturacion';
            foreach ($importes as $i => $importe) {
                $acumulado[$grupo][$i] += $importe;
            }
        }

        $this->ultimaFilaDatos = count($filas);

        $filas[] = [null];
        $this->filaPorFacturacion = count($filas) + 1;
        $filas[] = $this->filaTotal('Por Facturación:', $acumulado['facturacion']);
        $this->filaPorNotas = count($filas) + 1;
        $filas[] = $this->filaTotal('Por Nota de Crédito:', $acumulado['notas']);
        $this->filaTotales = count($filas) + 1;
        $filas[] = $this->filaTotal('Totales:', $this->sumar($acumulado['facturacion'], $acumulado['notas']));

        return $filas;
    }

    public function title(): string
    {
        return $this->titulo;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 11.5,  // Fecha
            'B' => 7,     // Tipo
            'C' => 18,    // N° de Comprobante
            'D' => 30,    // Razón Social
            'E' => 15,    // CUIT / DNI
            'F' => 20,    // Condición de IVA
            'G' => 14, 'H' => 13, 'I' => 15,   // netos
            'J' => 14,                          // IVA
            'K' => 16,                          // Total Facturado
            'L' => 16,                          // Provincia
            'M' => 18,                          // Medio de Cobro/Pago
        ];
    }

    public function columnFormats(): array
    {
        $formatos = ['A' => 'DD/MM/YYYY'];

        foreach (self::COLUMNAS_IMPORTE as $col) {
            $formatos[$col] = '0.00;(0.00)';
        }

        return $formatos;
    }

    public function styles(Worksheet $hoja)
    {
        $ultima = self::ULTIMA_COLUMNA;
        $finTotales = $this->filaTotales;

        $hoja->getStyle("A1:{$ultima}{$finTotales}")->getFont()->setName('Arial')->setSize(10);

        // --- Encabezado del negocio (filas 1-3) ---
        $hoja->getStyle('A1:A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $hoja->getStyle('F2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $hoja->getStyle('F3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // --- Títulos de columna ---
        $titulos = self::FILA_TITULOS;
        $hoja->getStyle("A{$titulos}:{$ultima}{$titulos}")->applyFromArray([
            'font' => ['color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_ENCABEZADO]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ]);
        $hoja->getRowDimension($titulos)->setRowHeight(27);

        // --- Cuerpo de datos ---
        if ($this->ultimaFilaDatos > $titulos) {
            $primerDato = $titulos + 1;
            // Texto a la izquierda (razón social, condición de IVA, provincia, medio).
            $hoja->getStyle("D{$primerDato}:F{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $hoja->getStyle("L{$primerDato}:M{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            // Fecha, tipo y número de comprobante centrados, como el original.
            $hoja->getStyle("A{$primerDato}:C{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            // Importes a la derecha.
            $hoja->getStyle("G{$primerDato}:K{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // --- Totales al pie: rótulos en negrita; los importes sólo en la fila de "Totales:" ---
        // El rótulo va en F (última columna antes de los importes) y los importes en G..K.
        foreach ([$this->filaPorFacturacion, $this->filaPorNotas, $this->filaTotales] as $fila) {
            $hoja->getStyle("F{$fila}")->getFont()->setBold(true);
            $hoja->getStyle("G{$fila}:K{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $hoja->getStyle("G{$this->filaTotales}:K{$this->filaTotales}")->getFont()->setBold(true);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento) {
                $hoja = $evento->sheet->getDelegate();

                // Igual que el archivo de Contagram: apaisado y sin grilla (FR-016).
                $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $hoja->setShowGridlines(false);
            },
        ];
    }

    /**
     * Fecha como valor real de Excel (no texto), con `numFmt` DD/MM/YYYY fijado en `columnFormats()`.
     *
     * Divergencia deliberada respecto de `Tesoreria\MovimientosExport`, que las escribe como texto para
     * que el locale del lector no las reinterprete: allá son dos fechas sueltas de cabecera que nadie
     * ordena; acá es la columna de un libro contable que el contador ordena y filtra, y como texto
     * `01/09` se ordenaría antes que `03/08`. El formato explícito en la celda es la misma mitigación
     * que usa el propio archivo de Contagram (spec 089, Decisión 1).
     */
    private function fechaExcel(string $fecha): ?float
    {
        if ($fecha === '') {
            return null;
        }

        return ExcelDate::PHPToExcel(new \DateTime(substr($fecha, 0, 10)));
    }

    private function textoPeriodo(): string
    {
        $mes = (int) $this->request->input('mes');
        $anio = (int) $this->request->input('anio');

        if ($mes < 1 || $mes > 12) {
            return (string) $anio;
        }

        return (new Periodo($anio, $mes))->nombreMes().' de '.$anio;
    }

    /**
     * Fila del encabezado con los valores en posiciones concretas y `null` en el resto, hasta la
     * última posición usada. Recibe pares `posición, valor`.
     */
    private function filaEncabezado(int|string ...$pares): array
    {
        $valores = [];

        for ($i = 0; $i < count($pares); $i += 2) {
            $valores[(int) $pares[$i]] = $pares[$i + 1];
        }

        $fila = array_fill(0, max(array_keys($valores)) + 1, null);

        foreach ($valores as $posicion => $valor) {
            $fila[$posicion] = $valor;
        }

        return $fila;
    }

    /** Mismo criterio que `IvaDigital\ComprobantesVentasWriter::esNota()`. */
    private function esNota(string $tipo): bool
    {
        return str_starts_with($tipo, 'NC') || str_starts_with($tipo, 'ND');
    }

    /** @return list<float> */
    private function ceros(): array
    {
        return array_fill(0, count(self::COLUMNAS_IMPORTE), 0.0);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    private function sumar(array $a, array $b): array
    {
        return array_map(fn (float $x, float $y) => round($x + $y, 2), $a, $b);
    }

    /**
     * Fila de total: el rótulo va en la columna F (la última antes de los importes) y los 5 importes
     * a continuación, alineados con sus columnas del detalle.
     *
     * @param  list<float>  $importes
     */
    private function filaTotal(string $rotulo, array $importes): array
    {
        return array_merge(
            [null, null, null, null, null, $rotulo],
            array_map(fn (float $v) => round($v, 2), $importes)
        );
    }

    /**
     * Las 13 columnas, con el rótulo de la última según el libro (FR-006): en Compras el medio es de
     * pago al proveedor, no de cobro.
     *
     * @return list<string>
     */
    private function encabezados(): array
    {
        $encabezados = self::ENCABEZADOS;

        if ($this->esCompras()) {
            $encabezados[array_key_last($encabezados)] = 'Medio de Pago';
        }

        return $encabezados;
    }

    private function esCompras(): bool
    {
        return str_contains(mb_strtolower($this->titulo), 'compras');
    }

    /** Misma clave que usa {@see DatosComercialesComprobante} para indexar sus resultados. */
    private function claveComercial(object $fila): string
    {
        if (($fila->origen ?? null) === 'historico_migracion_agosto_2026') {
            return 'historico:'.$fila->id;
        }

        return ($this->esNota((string) $fila->tipo) ? 'nota' : 'comprobante').':'.$fila->id;
    }
}
