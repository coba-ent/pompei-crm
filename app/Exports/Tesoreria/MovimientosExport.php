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
 * 1. El nombre de la sección va DOS veces —una con fondo azul y otra con fondo gris—. Parece la
 *    estructura genérica sección + grupo del reporte, que con un solo grupo colapsa en la misma
 *    palabra.
 * 2. "Total Cobros" aparece dos veces (subtotal del grupo y total de la sección), pero
 *    "Total Pagos" una sola. La asimetría está en el archivo original.
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
     * Tipos de cuenta que lista cada sección, más la excepción.
     *
     * Contagram no lista sólo las cuentas con movimiento: lista TODAS las que aplican a la
     * sección, y las que no tuvieron nada van en 0. Esta agrupación se dedujo del archivo
     * relevado: Cobros = efectivo + banco + a_cobrar (18 filas), Pagos = efectivo + banco +
     * a_pagar (12) **más "Cheque de Terceros"**, que es `a_cobrar` y aun así aparece (13).
     *
     * Esa excepción no se pudo derivar de los datos —16 cuentas registran pagos alguna vez y el
     * archivo lista 13, así que tampoco es "las que alguna vez pagaron"—. Tiene sentido de
     * negocio (un cheque de terceros recibido se endosa para pagar), pero está calibrada contra
     * UN archivo. Si aparece otra cuenta que se comporte igual, va acá.
     */
    private const TIPOS_COBROS = ['efectivo', 'banco', 'a_cobrar'];

    private const TIPOS_PAGOS = ['efectivo', 'banco', 'a_pagar'];

    private const PAGOS_EXTRA = ['Cheque de Terceros'];

    /**
     * Cuentas que Contagram manda al final de su sección, fuera del orden alfabético.
     * En el archivo relevado "Cheque de Terceros" cierra tanto Cobros como Pagos.
     */
    private const AL_FINAL = ['Cheque de Terceros'];

    /**
     * @param  array{cobros: list<array{nombre: string, monto: float}>, pagos: list<array{nombre: string, monto: float}>, total_cobros: float, total_pagos: float, resultado: float}  $flujo
     * @param  \Illuminate\Support\Collection<int, \App\Models\CuentaTesoreria>  $cuentas
     */
    public function __construct(
        private array $flujo,
        private Carbon $desde,
        private Carbon $hasta,
        private $cuentas,
    ) {}

    /**
     * Filas de una sección: todas las cuentas que aplican, con su importe o 0.
     *
     * Se ordena por tipo —en el orden en que Contagram los muestra— y alfabéticamente dentro de
     * cada tipo, que es como aparece en el archivo relevado.
     *
     * @param  list<string>  $tipos
     * @param  list<string>  $extra
     * @param  list<array{nombre: string, monto: float}>  $conMovimiento
     * @param  int  $signo  1 para Cobros, -1 para Pagos (ver el comentario de `array()`)
     * @return list<array{nombre: string, monto: float}>
     */
    private function filasDeSeccion(array $tipos, array $extra, array $conMovimiento, int $signo = 1): array
    {
        $montos = [];
        foreach ($conMovimiento as $fila) {
            $montos[$fila['nombre']] = (float) $fila['monto'];
        }

        return $this->cuentas
            ->filter(fn ($c) => in_array($c->tipo, $tipos, true) || in_array($c->nombre, $extra, true))
            ->sortBy([
                fn ($a, $b) => in_array($a->nombre, self::AL_FINAL, true) <=> in_array($b->nombre, self::AL_FINAL, true),
                fn ($a, $b) => array_search($a->tipo, $tipos, true) <=> array_search($b->tipo, $tipos, true),
                fn ($a, $b) => strcasecmp($a->nombre, $b->nombre),
            ])
            ->map(fn ($c) => [
                'nombre' => $c->nombre,
                'monto' => round(($montos[$c->nombre] ?? 0.0) * $signo, 2),
            ])
            ->values()
            ->all();
    }

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

        foreach ($this->filasDeSeccion(self::TIPOS_COBROS, [], $this->flujo['cobros']) as $fila) {
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

        foreach ($this->filasDeSeccion(self::TIPOS_PAGOS, self::PAGOS_EXTRA, $this->flujo['pagos'], -1) as $fila) {
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
