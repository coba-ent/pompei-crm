<?php

namespace App\Exports\Tesoreria;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export del Informe de Movimientos de Tesorería, calcado del XLSX que genera Contagram.
 *
 * Reemplaza al CSV que devolvía esta pantalla. Los datos no cambiaron —salen del mismo
 * `Tesoreria::flujo()`—; lo que se replica es la disposición exacta del archivo de Contagram
 * ("Informe Final 16-08-2026 1747 Hs.xlsx", relevado el 16/08/2026):
 *
 *     C2                título "Movimientos"
 *     fila 4/5          Desde · Hasta · Total Cobros · Total Pagos · Resultado
 *     banda azul/gris   nombre de la sección, repetido en dos filas
 *     fila de columnas  "Descripción" (A) … "Total" (E)
 *     una fila x cuenta nombre en A, importe en E
 *     total             etiqueta en D, importe en E
 *
 * DOS RAREZAS QUE SE COPIAN A PROPÓSITO (regla de oro: calcar Contagram, no "mejorarlo"):
 *
 * 1. El nombre de la sección va DOS veces —una con fondo azul y otra con fondo gris—. Es la
 *    estructura genérica sección + grupo del reporte, que con un solo grupo colapsa en la misma
 *    palabra.
 * 2. "Total Cobros" aparece dos veces (subtotal del grupo y total de la sección), pero
 *    "Total Pagos" una sola. La asimetría está en el archivo original.
 *
 * Las dos se confirmaron el 16/08/2026 contra el PDF de Contagram del mismo informe, que las
 * repite igual: no son un defecto de su exportador de Excel, es cómo está armado el reporte.
 *
 * Qué cuentas lista cada sección y con qué signo lo decide `SeccionesMovimientos`, compartido
 * con el PDF.
 *
 * Lo único que NO se copia es la basura de coma flotante: el archivo trae `30455413.030000005`
 * en el total de la cabecera y `30455413.03` en el de la sección, para el mismo número. Se
 * redondea a 2 decimales en todos lados — es un error de representación, no un dato.
 *
 * `WithStrictNullComparison` no es opcional: sin él PhpSpreadsheet compara cada celda contra
 * `null` con `==`, y como en PHP `0 == null`, una cuenta con saldo 0 real —de las que este
 * informe lista varias— no se escribiría en el archivo.
 */
class MovimientosExport implements FromArray, WithColumnWidths, WithStrictNullComparison, WithStyles, WithTitle
{
    /** Fila (base 1) donde arranca cada bloque; se completa al armar el array. */
    private int $filaSeccionCobros = 0;

    private int $filaSeccionPagos = 0;

    /** @var list<int> filas de "Descripción/Total" */
    private array $filasEncabezado = [];

    /** @var list<int> filas de totales */
    private array $filasTotal = [];

    private int $ultimaFila = 0;

    /**
     * @param  array{total_cobros: float, total_pagos: float, resultado: float}  $flujo
     * @param  array{cobros: list<array{nombre: string, monto: float}>, pagos: list<array{nombre: string, monto: float}>}  $secciones
     *         ya armadas por `SeccionesMovimientos` — las mismas que consume el PDF, para que los
     *         dos informes no se puedan desincronizar.
     */
    public function __construct(
        private array $flujo,
        private Carbon $desde,
        private Carbon $hasta,
        private array $secciones,
    ) {}

    public function title(): string
    {
        return 'Informe Final';
    }

    public function columnWidths(): array
    {
        // Anchos exactos del archivo de Contagram.
        return ['A' => 20.17, 'B' => 15.37, 'C' => 26.48, 'D' => 20.60, 'E' => 26.90];
    }

    public function array(): array
    {
        $vacia = [null, null, null, null, null];
        $filas = [];

        $filas[] = $vacia;                                                              // 1
        $filas[] = [null, null, 'Movimientos', null, null];                             // 2
        $filas[] = $vacia;                                                              // 3
        $filas[] = ['Desde', 'Hasta', 'Total Cobros', 'Total Pagos', 'Resultado'];      // 4
        $filas[] = [
            $this->desde->format('d/m/Y'),
            $this->hasta->format('d/m/Y'),
            round((float) $this->flujo['total_cobros'], 2),
            // Contagram muestra los pagos en NEGATIVO; `flujo()` los devuelve en valor absoluto.
            round((float) $this->flujo['total_pagos'] * -1, 2),
            round((float) $this->flujo['resultado'], 2),
        ];                                                                              // 5
        $filas[] = $vacia;                                                              // 6

        // ---- Cobros ----
        $this->filaSeccionCobros = count($filas) + 1;
        $filas[] = ['Cobros', null, null, null, null];
        $filas[] = ['Cobros', null, null, null, null];
        $this->filasEncabezado[] = count($filas) + 1;
        $filas[] = ['Descripción', null, null, null, 'Total'];

        foreach ($this->secciones['cobros'] as $fila) {
            $filas[] = [$fila['nombre'], null, null, null, $fila['monto']];
        }

        $this->filasTotal[] = count($filas) + 1;
        $filas[] = [null, null, null, 'Total Cobros', round((float) $this->flujo['total_cobros'], 2)];
        $filas[] = $vacia;
        // Repetido en el original: subtotal de grupo y total de sección (ver el comentario de clase).
        $this->filasTotal[] = count($filas) + 1;
        $filas[] = [null, null, null, 'Total Cobros', round((float) $this->flujo['total_cobros'], 2)];
        $filas[] = $vacia;

        // ---- Pagos ----
        $this->filaSeccionPagos = count($filas) + 1;
        $filas[] = ['Pagos', null, null, null, null];
        $filas[] = ['Pagos', null, null, null, null];
        $this->filasEncabezado[] = count($filas) + 1;
        $filas[] = ['Descripción', null, null, null, 'Total'];

        foreach ($this->secciones['pagos'] as $fila) {
            $filas[] = [$fila['nombre'], null, null, null, $fila['monto']];
        }

        $this->filasTotal[] = count($filas) + 1;
        $filas[] = [null, null, null, 'Total Pagos', round((float) $this->flujo['total_pagos'] * -1, 2)];
        $filas[] = $vacia;

        $this->ultimaFila = count($filas);

        return $filas;
    }

    public function styles(Worksheet $hoja)
    {
        // Las fechas van como TEXTO, igual que en el original. Sin esto PhpSpreadsheet las
        // interpreta como fecha de Excel y las vuelve a dibujar con el locale de quien abre el
        // archivo — exactamente el problema que ya nos costó los inputs `type="date"`.
        $hoja->setCellValueExplicit('A5', $this->desde->format('d/m/Y'), DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('B5', $this->hasta->format('d/m/Y'), DataType::TYPE_STRING);

        $hoja->getStyle('A1:E'.$this->ultimaFila)->getFont()->setSize(12);

        $hoja->getStyle('C2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $hoja->getStyle('A4:E4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $hoja->getStyle('A5:E5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ([$this->filaSeccionCobros, $this->filaSeccionPagos] as $fila) {
            $hoja->getStyle("A{$fila}:E{$fila}")->applyFromArray([
                'font' => ['size' => 14],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E5DA1']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $gris = $fila + 1;
            $hoja->getStyle("A{$gris}:E{$gris}")->applyFromArray([
                'font' => ['size' => 14],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C5C9CC']],
            ]);
        }

        foreach ($this->filasEncabezado as $fila) {
            $hoja->getStyle("A{$fila}:E{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        foreach ($this->filasTotal as $fila) {
            $hoja->getStyle("D{$fila}:E{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
            ]);
        }

        return [];
    }
}
