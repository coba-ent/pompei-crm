<?php

namespace App\Exports\Informes;

use App\Services\Informes\ReporteFinalQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del Reporte Final (spec 068, US3): dos hojas de la vista exportada.
 *
 * - **"Informe Final"**: hoja legible, con el layout de Contagram —cabecera Desde/Hasta/Total
 *   Ingresos/Total Egresos/Resultado, bloques con subtotal y el árbol de categorías. **Acá vive
 *   la réplica R2.**
 * - **"Detalle"**: hoja plana, una fila por combinación (bloque · categoría · subcategoría ·
 *   cuenta de tesorería), **todo en positivo** más una columna que dice si es ingreso o egreso.
 *   Es la hoja para reprocesar, así que no puede arrastrar el doble estándar de signos.
 *
 * Las dos respetan el escenario del simulador: las categorías destildadas no aparecen ni suman
 * (FR-006). Y las dos completan siempre Desde y Hasta — Contagram las deja vacías en la hoja de
 * "Cobros Vs Pagos", pero eso es una omisión sin valor informativo, no una regla de cálculo, así
 * que **no** se replica (FR-039, Clarifications).
 */
class ReporteFinalExport implements WithMultipleSheets
{
    public function __construct(private ReporteFinalQuery $informe, private Request $request) {}

    public function sheets(): array
    {
        $arbol = $this->informe->arbol($this->request);
        $excluidas = $this->informe->excluidas($this->request);

        return [
            $this->hojaLegible($arbol, $excluidas),
            $this->hojaPlana($arbol, $excluidas),
        ];
    }

    /**
     * RÉPLICA DELIBERADA R2 — NO "CORREGIR" (spec 068 §Réplicas, FR-036).
     *
     * Contagram exporta las dos pestañas del Reporte Final con **convenciones de signo
     * distintas**, aunque en pantalla las muestra igual:
     *
     * | Hoja               | Total Egresos | Resultado           | Subtotales de bloque |
     * |--------------------|---------------|---------------------|----------------------|
     * | Ventas Vs. Compras | negativo      | Ingresos **+** Egr. | negativos            |
     * | Cobros Vs Pagos    | positivo      | Ingresos **−** Egr. | negativos, con las líneas por cuenta en positivo |
     *
     * El usuario pidió fidelidad total para que los números coincidan al comparar contra la app
     * original. El desvío está confinado a esta hoja: la pantalla, el PDF y la hoja plana usan
     * siempre egresos en positivo y `Resultado = Ingresos − Egresos`. `ReporteFinalReplicasTest`
     * lo fija por escrito.
     *
     * @param  list<string>  $excluidas
     */
    private function hojaLegible(array $arbol, array $excluidas): HojaInforme
    {
        $esCaja = $arbol['vista'] === 'caja';
        $totales = $this->informe->totales($arbol['bloques'], $excluidas);

        // En la hoja devengado el egreso viaja negativo y el resultado se **suma**; en la de caja
        // el total va positivo y se resta. Las dos dan el mismo número: cambia sólo la escritura.
        $totalEgresos = $esCaja ? $totales['egresos'] : -$totales['egresos'];
        $resultado = $esCaja
            ? round($totales['ingresos'] - $totalEgresos, 2)
            : round($totales['ingresos'] + $totalEgresos, 2);

        $filas = [
            ['Desde', $arbol['desde']],
            ['Hasta', $arbol['hasta']],
            ['Total Ingresos', $totales['ingresos']],
            ['Total Egresos', $totalEgresos],
            ['Resultado', $resultado],
            [],
        ];

        $destacadas = [];

        foreach ($arbol['bloques'] as $bloque) {
            $categorias = $this->visibles($bloque, $excluidas);

            if ($categorias === []) {
                continue;
            }

            $egreso = $bloque['naturaleza'] === 'egreso';
            // En la vista caja las líneas individuales por cuenta van en positivo aunque el
            // subtotal del bloque vaya en negativo: es exactamente el doble estándar de origen.
            $signoLinea = ($egreso && ! $esCaja) ? -1 : 1;

            $filas[] = [$bloque['etiqueta']];
            $destacadas[] = count($filas);

            foreach ($categorias as $categoria) {
                $filas[] = ['   '.$categoria['etiqueta'], round($signoLinea * $categoria['monto'], 2)];

                foreach ($categoria['hijos'] as $hijo) {
                    $filas[] = ['      '.$hijo['etiqueta'], round($signoLinea * $hijo['monto'], 2)];

                    foreach ($hijo['hijos'] as $nieto) {
                        $filas[] = ['         '.$nieto['etiqueta'], round($signoLinea * $nieto['monto'], 2)];
                    }
                }
            }

            // Los subtotales de bloque de egresos van en negativo en **las dos** hojas (FR-036).
            $subtotal = $this->informe->totalBloque($bloque, $excluidas);
            $filas[] = ['Total '.$bloque['etiqueta'], $egreso ? round(-$subtotal, 2) : $subtotal];
            $destacadas[] = count($filas);
            $filas[] = [];
        }

        return new HojaInforme(
            'Informe Final',
            ['Descripción', 'Total'],
            $filas,
            $destacadas,
        );
    }

    /** @param  list<string>  $excluidas */
    private function hojaPlana(array $arbol, array $excluidas): HojaInforme
    {
        $vista = $arbol['vista'] === 'caja' ? 'Cobros Vs Pagos' : 'Ventas Vs. Compras';
        $esCaja = $arbol['vista'] === 'caja';
        $filas = [];

        foreach ($arbol['bloques'] as $bloque) {
            foreach ($this->visibles($bloque, $excluidas) as $categoria) {
                if ($categoria['hijos'] === []) {
                    $filas[] = $this->filaPlana($vista, $bloque, $categoria['etiqueta'], '', '', $categoria['monto']);

                    continue;
                }

                foreach ($categoria['hijos'] as $hijo) {
                    // En Gastos el segundo nivel es la subcategoría; en el resto de los bloques de
                    // la vista caja es directamente la cuenta de tesorería.
                    $esSubcategoria = $bloque['clave'] === 'gastos' && $esCaja;

                    if ($hijo['hijos'] === []) {
                        $filas[] = $esCaja && ! $esSubcategoria
                            ? $this->filaPlana($vista, $bloque, $categoria['etiqueta'], '', $hijo['etiqueta'], $hijo['monto'])
                            : $this->filaPlana($vista, $bloque, $categoria['etiqueta'], $hijo['etiqueta'], '', $hijo['monto']);

                        continue;
                    }

                    foreach ($hijo['hijos'] as $nieto) {
                        $filas[] = $this->filaPlana($vista, $bloque, $categoria['etiqueta'], $hijo['etiqueta'], $nieto['etiqueta'], $nieto['monto']);
                    }
                }
            }
        }

        return new HojaInforme(
            'Detalle',
            ['Vista', 'Bloque', 'Naturaleza', 'Categoría', 'Subcategoría', 'Cuenta de Tesorería', 'Monto'],
            $filas,
        );
    }

    /** Fila de la hoja plana: siempre en positivo, con la naturaleza como columna. */
    private function filaPlana(string $vista, array $bloque, string $categoria, string $subcategoria, string $cuenta, float $monto): array
    {
        return [
            $vista,
            $bloque['etiqueta'],
            $bloque['naturaleza'] === 'ingreso' ? 'Ingreso' : 'Egreso',
            $categoria,
            $subcategoria,
            $cuenta,
            round($monto, 2),
        ];
    }

    /**
     * Categorías del bloque que sobreviven al simulador.
     *
     * @param  list<string>  $excluidas
     * @return list<array<string, mixed>>
     */
    private function visibles(array $bloque, array $excluidas): array
    {
        return array_values(array_filter(
            $bloque['categorias'],
            fn (array $categoria) => ! in_array($categoria['clave'], $excluidas, true)
        ));
    }
}
