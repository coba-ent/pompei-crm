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

        $this->normalizarNumeros();
    }

    /**
     * Convierte a número real los importes que llegan formateados en es-AR.
     *
     * La matriz se lee del DOM con `.text()` (ver `matrizVisible()` en informes-pivot.js), así que
     * cada celda viaja como el string que ve el usuario: `"30.505.482,68"`. Escrito tal cual, el
     * Excel lo guarda como TEXTO — Excel lo marca con el triangulito verde y, según la
     * configuración regional de quien abra el archivo, la columna Total puede llegar a mostrar 0
     * en vez del importe. Con cantidades no se notaba (`"23"` es numérico en cualquier locale),
     * por eso el problema aparecía sólo en los rankings en dinero.
     *
     * El formato es siempre el mismo (`thousandsSep: '.'`, `decimalSep: ','`, fijado en
     * informes-pivot.js para todos los agregadores), así que la conversión es determinista. Lo que
     * no sea un número con ese formato —una etiqueta, una celda vacía— se deja intacto.
     */
    private function normalizarNumeros(): void
    {
        foreach ($this->datos['filas'] as $i => $fila) {
            $this->datos['filas'][$i]['valores'] = array_map(
                fn ($v) => self::aNumero($v),
                $fila['valores'],
            );

            if (array_key_exists('total', $fila)) {
                $this->datos['filas'][$i]['total'] = self::aNumero($fila['total']);
            }
        }

        $this->datos['totales_columna'] = array_map(
            fn ($v) => self::aNumero($v),
            $this->datos['totales_columna'],
        );

        $this->datos['total_general'] = self::aNumero($this->datos['total_general']);
    }

    /**
     * `"30.505.482,68"` → `30505482.68`. Devuelve el valor original si no es un número en es-AR
     * (una etiqueta, `null`, una celda vacía o un porcentaje con sufijo).
     */
    private static function aNumero(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '') {
            return $valor;
        }

        if (! preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/', $valor)) {
            return $valor;
        }

        return (float) str_replace(',', '.', str_replace('.', '', $valor));
    }

    public function sheets(): array
    {
        return [
            $this->hojaLegible(),
            new HojaInforme('Plana', ['Fila', 'Columna', 'Valor'], $this->filasPlanas(), conAutofiltro: true),
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

        $filas = $this->filasLegible();

        return new HojaInforme(
            'Informe',
            $this->encabezadosLegible(),
            $filas,
            // La fila de totales se resalta: es la última, venga de la matriz o la agregue
            // `filasLegible()`. Se cuenta sobre las filas ya armadas y no sobre las de la matriz,
            // que puede traerla o no.
            [count($filas)],
            conAutofiltro: true,
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

        return new HojaInforme('Informe', $encabezados, $filas, [count($filas)], conAutofiltro: true);
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
            // La fila de totales que trae la matriz se omite acá y se arma más abajo: su etiqueta
            // ocupa una sola celda mientras que las de datos ocupan una por dimensión, así que
            // copiarla tal cual correría sus valores una columna a la izquierda.
            if (! empty($fila['es_total'])) {
                continue;
            }

            $filas[] = array_merge(
                $fila['etiqueta'],
                $fila['valores'],
                [$fila['total'] ?? array_sum(array_filter($fila['valores'], 'is_numeric'))],
            );
        }

        // Fila de totales al pie, alineada con las columnas del cruce. Se arma siempre acá —y no
        // se copia la de la matriz— para que las etiquetas de las dimensiones de filas queden
        // rellenadas con nulos y los valores caigan bajo su columna.
        // El relleno va con `array_fill(0, ...)`: arrancándolo en 1 las claves no son una lista y
        // `array_merge` descartaba una celda, con lo que los totales caían una columna corrida
        // respecto de su mes.
        $relleno = max(count($this->datos['encabezados_fila']) - 1, 0);

        $filas[] = array_merge(
            ['Total'],
            $relleno > 0 ? array_fill(0, $relleno, null) : [],
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
