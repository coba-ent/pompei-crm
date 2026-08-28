<?php

namespace App\Exports\Informes;

use App\Models\DatosEmpresa;
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
    private const ENCABEZADOS = [
        'Id', 'Emisión', 'Tipo', 'N° de Comprobante', 'Cliente/Proveedor', 'CUIT/DNI', 'Condición de IVA',
        'Importe Neto No Gravado', 'Importe Neto Exento', 'Importe Neto Gravado',
        'IVA 2,5%', 'IVA 5%', 'IVA 10,5%', 'IVA 21%', 'IVA 27%',
        'Perc. IVA', 'Perc. IIBB', 'Imp. Internos', 'Imp. Municipales',
    ];

    /** Azul corporativo de Contagram, ya usado en `Tesoreria\MovimientosExport`. */
    private const AZUL_ENCABEZADO = '0E5DA1';

    private const ULTIMA_COLUMNA = 'S';

    /** Fila (base 1) de los títulos de columna. Fija: el encabezado del negocio ocupa 1-4. */
    private const FILA_TITULOS = 5;

    /** Índices (base 0 dentro de la fila) de las columnas de importe — las 12 que llevan formato numérico. */
    private const COLUMNAS_IMPORTE = ['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];

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
            self::ENCABEZADOS,
        ];

        $acumulado = ['facturacion' => $this->ceros(), 'notas' => $this->ceros()];

        foreach ($this->informe->detalle($this->request)->get() as $fila) {
            $importes = [
                (float) $fila->neto_no_gravado, (float) $fila->neto_exento, (float) $fila->neto_gravado,
                (float) $fila->iva_2_5, (float) $fila->iva_5, (float) $fila->iva_10_5, (float) $fila->iva_21, (float) $fila->iva_27,
                (float) $fila->perc_iva, (float) $fila->perc_iibb, (float) $fila->imp_internos, (float) $fila->imp_municipales,
            ];

            $filas[] = array_merge([
                $fila->id,
                $this->fechaExcel((string) $fila->emision),
                $fila->tipo,
                $fila->nro_comprobante,
                $fila->contraparte,
                $fila->cuit,
                $fila->condicion_iva,
            ], $importes);

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
            'A' => 8.5,   // Id
            'B' => 11.5,  // Emisión
            'C' => 7,     // Tipo
            'D' => 18,    // N° de Comprobante
            'E' => 30,    // Cliente/Proveedor
            'F' => 15,    // CUIT/DNI
            'G' => 20,    // Condición de IVA
            'H' => 15, 'I' => 13, 'J' => 15,          // netos
            'K' => 10, 'L' => 10, 'M' => 11, 'N' => 12, 'O' => 10,  // alícuotas
            'P' => 11, 'Q' => 11, 'R' => 13, 'S' => 15,             // percepciones e impuestos
        ];
    }

    public function columnFormats(): array
    {
        $formatos = ['B' => 'DD/MM/YYYY'];

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
            $hoja->getStyle("A{$primerDato}:G{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $hoja->getStyle("B{$primerDato}:D{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $hoja->getStyle("H{$primerDato}:{$ultima}{$this->ultimaFilaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // --- Totales al pie: rótulos en negrita; los importes sólo en la fila de "Totales:" ---
        foreach ([$this->filaPorFacturacion, $this->filaPorNotas, $this->filaTotales] as $fila) {
            $hoja->getStyle("G{$fila}")->getFont()->setBold(true);
            $hoja->getStyle("H{$fila}:{$ultima}{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $hoja->getStyle("H{$this->filaTotales}:{$ultima}{$this->filaTotales}")->getFont()->setBold(true);

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
     * Fila de total: el rótulo va en la columna G (la última antes de los importes) y los 12 importes
     * a continuación, alineados con sus columnas del detalle.
     *
     * @param  list<float>  $importes
     */
    private function filaTotal(string $rotulo, array $importes): array
    {
        return array_merge(
            [null, null, null, null, null, null, $rotulo],
            array_map(fn (float $v) => round($v, 2), $importes)
        );
    }
}
