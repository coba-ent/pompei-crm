<?php

namespace App\Exports\Informes;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del cruce visible de Rankings / "Arma tu Informe" (spec 069).
 *
 * **No recalcula nada**: recibe la matriz tal como la dejó el cliente. El usuario pudo excluir
 * valores con el embudo, reordenar dimensiones o mover una de filas a columnas, y todo eso vive
 * en el navegador — recalcularlo en el servidor daría un archivo distinto de lo que está viendo,
 * que es justo lo que un export no puede hacer (research R3).
 *
 * Dos hojas, como el resto de los informes del módulo:
 *   1. **legible**: el cruce con sus encabezados y su fila/columna de totales.
 *   2. **plana**: una fila por combinación (fila × columna), para reprocesar en otra planilla.
 */
class PivotExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array{titulo: string, encabezados_fila: list<string>, encabezados_columna: list<string>, filas: list<array{etiqueta: list<string>, valores: list<mixed>, total?: mixed}>, totales_columna: list<mixed>, total_general: mixed}  $datos
     */
    public function __construct(private array $datos)
    {
    }

    public function sheets(): array
    {
        return [
            new HojaInforme(
                'Informe',
                $this->encabezadosLegible(),
                $this->filasLegible(),
                // La fila de totales se resalta: es la última.
                [count($this->datos['filas']) + 1],
            ),
            new HojaInforme('Plana', ['Fila', 'Columna', 'Valor'], $this->filasPlanas()),
        ];
    }

    /** @return list<string> */
    private function encabezadosLegible(): array
    {
        return array_merge(
            $this->datos['encabezados_fila'] ?: ['Descripción'],
            $this->datos['encabezados_columna'],
            ['Total'],
        );
    }

    /** @return list<list<mixed>> */
    private function filasLegible(): array
    {
        $filas = [];

        foreach ($this->datos['filas'] as $fila) {
            $filas[] = array_merge(
                $fila['etiqueta'],
                $fila['valores'],
                [$fila['total'] ?? array_sum(array_filter($fila['valores'], 'is_numeric'))],
            );
        }

        // Fila de totales al pie, alineada con las columnas del cruce.
        $filas[] = array_merge(
            ['Total'],
            array_fill(1, max(count($this->datos['encabezados_fila']) - 1, 0), null),
            $this->datos['totales_columna'],
            [$this->datos['total_general']],
        );

        return $filas;
    }

    /**
     * Una fila por celda del cruce.
     *
     * Las celdas vacías **no se emiten**: en un cruce grande la mayoría lo están, y llenarlas de
     * ceros haría una hoja enorme que dice lo mismo.
     *
     * @return list<list<mixed>>
     */
    private function filasPlanas(): array
    {
        $planas = [];
        $columnas = $this->datos['encabezados_columna'];

        foreach ($this->datos['filas'] as $fila) {
            $etiqueta = implode(' › ', $fila['etiqueta']);

            foreach ($fila['valores'] as $i => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }

                $planas[] = [$etiqueta, $columnas[$i] ?? '', $valor];
            }
        }

        return $planas;
    }
}
