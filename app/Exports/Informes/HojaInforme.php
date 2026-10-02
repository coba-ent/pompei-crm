<?php

namespace App\Exports\Informes;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja de cualquiera de los tres Excel de informes (spec 067, US4).
 *
 * Los tres exportan **exactamente dos hojas** (contrato §4): una formateada, que respeta las
 * agrupaciones y subtotales de la pantalla, y una plana, de una fila por registro, pensada para
 * reprocesar en otra planilla. Ambas son la misma estructura —título + encabezados + filas—, así
 * que se arman con esta clase en vez de con seis clases casi idénticas.
 *
 * `WithStrictNullComparison` no es opcional: sin él PhpSpreadsheet compara cada celda contra
 * `null` con `==`, y como en PHP `0 == null`, un importe en 0 real no se escribe en el archivo
 * (mismo motivo documentado en `ProductosExport`).
 */
class HojaInforme implements FromArray, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $encabezados
     * @param  list<list<mixed>>  $filas  valores **ya calculados**: el Excel no lleva fórmulas (FR-044)
     * @param  list<int>  $filasDestacadas  índices (base 1 dentro de $filas) a resaltar, p. ej. subtotales
     */
    public function __construct(
        private string $titulo,
        private array $encabezados,
        private array $filas,
        private array $filasDestacadas = [],
        private bool $conAutofiltro = false,
    ) {}

    public function title(): string
    {
        return $this->titulo;
    }

    public function array(): array
    {
        return array_merge([$this->encabezados], $this->filas);
    }

    /** Una fila sin ningún valor: la separadora entre el detalle y los totales. */
    private function esFilaVacia(?array $fila): bool
    {
        if ($fila === null) {
            return false;
        }

        foreach ($fila as $valor) {
            if ($valor !== null && $valor !== '') {
                return false;
            }
        }

        return true;
    }

    public function styles(Worksheet $sheet)
    {
        $ultima = $sheet->getHighestColumn();

        $sheet->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B2B2B']],
        ]);

        foreach ($this->filasDestacadas as $indice) {
            // +1 por la fila de encabezados.
            $fila = $indice + 1;
            $sheet->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFEFEF']],
            ]);
        }

        foreach (range('A', $ultima) as $columna) {
            $sheet->getColumnDimension($columna)->setAutoSize(true);
        }

        // Filtro ya activado sobre el encabezado y las filas de datos, sin incluir la de totales:
        // el usuario filtra por proveedor apenas abre el archivo, que es como venía trabajando con
        // los exports de Contagram. La fila de totales queda afuera del rango para que no se
        // mezcle entre los valores del desplegable ni desaparezca al filtrar.
        if ($this->conAutofiltro && $this->filas !== []) {
            $ultimaFila = count($this->filas) + 1;

            // Se recortan todas las filas destacadas que estén al pie, no sólo la última: el
            // Informe de Ventas cierra con once filas de KPIs más una en blanco, y dejarlas
            // dentro del rango metería sus rótulos entre los valores del desplegable.
            $destacadas = $this->filasDestacadas;
            sort($destacadas);

            while ($destacadas !== [] && end($destacadas) + 1 === $ultimaFila) {
                array_pop($destacadas);
                $ultimaFila--;
            }

            // La fila en blanco que separa el detalle de los totales tampoco entra.
            while ($ultimaFila > 1 && $this->esFilaVacia($this->filas[$ultimaFila - 2] ?? null)) {
                $ultimaFila--;
            }

            if ($ultimaFila > 1) {
                $sheet->setAutoFilter("A1:{$ultima}{$ultimaFila}");
            }
        }

        return [];
    }
}
