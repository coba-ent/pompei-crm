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
     * @param  array{titulo: string, encabezados_fila: list<string>, encabezados_columna: list<string>, niveles_columna?: list<array{etiqueta: string, valores: list<string>}>, filas: list<array{etiqueta: list<string>, valores: list<mixed>, total?: mixed}>, totales_columna: list<mixed>, total_general: mixed}  $datos
     */
    public function __construct(private array $datos)
    {
        $this->datos['niveles_columna'] ??= [];
    }

    public function sheets(): array
    {
        return [
            $this->hojaLegible(),
            new HojaInforme('Plana', ['Fila', 'Columna', 'Valor'], $this->filasPlanas()),
        ];
    }

    private function hojaLegible(): HojaInforme
    {
        // Sin dimensión de Filas (sólo Columnas — el caso real de "Rankings 25-8-2026.xlsx" de
        // Contagram: Categorías>Clientes>Vendedores>Proveedores, nada en Filas) el cruce no tiene
        // filas de detalle propiamente dichas — todo el dato es la fila de totales. Se calca la
        // estructura real de ese export en vez de forzar el layout genérico de abajo.
        if ($this->datos['filas'] === [] && ! empty($this->datos['niveles_columna'])) {
            return $this->hojaLegibleSinFilas();
        }

        return new HojaInforme(
            'Informe',
            $this->encabezadosLegible(),
            $this->filasLegible(),
            // La fila de totales se resalta: es la última.
            [count($this->datos['filas']) + 1],
        );
    }

    /**
     * Calca la estructura real de Contagram para un cruce sin Filas: una fila de Excel por cada
     * NIVEL de columna (categorías / clientes / vendedores / proveedores, apiladas — no un único
     * encabezado con los niveles combinados en un string), y una sola fila de datos al pie
     * ("Totales") con el total por columna + el total general. El rótulo "Totales" de la última
     * columna sólo aparece una vez, en la primera fila de nivel — igual que en el archivo real,
     * donde esa celda tiene rowspan sobre las demás.
     */
    private function hojaLegibleSinFilas(): HojaInforme
    {
        $niveles = $this->datos['niveles_columna'];
        $totales = $this->datos['totales_columna'];

        $primero = array_shift($niveles);
        $encabezados = array_merge([$primero['etiqueta']], $primero['valores'], ['Totales']);

        $filas = [];
        foreach ($niveles as $nivel) {
            $filas[] = array_merge([$nivel['etiqueta']], $nivel['valores'], [null]);
        }
        $filas[] = array_merge(['Totales'], $totales, [$this->datos['total_general']]);

        return new HojaInforme('Informe', $encabezados, $filas, [count($filas)]);
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
        if ($this->datos['filas'] === []) {
            return $this->filasPlanasSinFilas();
        }

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

    /** Espejo de {@see hojaLegibleSinFilas()}: una fila por columna del cruce (sin Filas). */
    private function filasPlanasSinFilas(): array
    {
        if (empty($this->datos['niveles_columna'])) {
            return [];
        }

        $niveles = $this->datos['niveles_columna'];
        $totales = $this->datos['totales_columna'];
        $planas = [];

        foreach ($totales as $i => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            $etiqueta = implode(' › ', array_map(fn (array $n) => $n['valores'][$i] ?? '', $niveles));
            $planas[] = [$etiqueta, '', $valor];
        }

        return $planas;
    }
}
