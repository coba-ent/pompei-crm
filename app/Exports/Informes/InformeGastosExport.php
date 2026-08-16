<?php

namespace App\Exports\Informes;

use App\Services\Informes\GastosInformeQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del Informe de Gastos (spec 067, US4): dos hojas.
 *
 * - **Gastos**: hoja jerárquica que replica lo que se ve en pantalla — encabezado de Categoría,
 *   de Subcategoría, sus gastos y la fila de subtotal.
 * - **Detalle plano**: una fila por gasto, sin encabezados de sección, para tabla dinámica.
 */
class InformeGastosExport implements WithMultipleSheets
{
    private const CHUNK = 1000;

    public function __construct(private GastosInformeQuery $informe, private Request $request) {}

    public function sheets(): array
    {
        $filas = $this->filas();

        return [
            $this->hojaJerarquica($filas),
            $this->hojaPlana($filas),
        ];
    }

    /** @return list<\stdClass> */
    private function filas(): array
    {
        $acumulado = [];

        $this->informe->detalle($this->request)
            ->orderBy('categoria')->orderBy('subcategoria')->orderBy('fecha')->orderBy('id')
            ->chunk(self::CHUNK, function ($chunk) use (&$acumulado) {
                foreach ($chunk as $fila) {
                    $acumulado[] = $fila;
                }
            });

        return $acumulado;
    }

    /**
     * Hoja formateada. Los subtotales salen de `subtotales()` —el mismo cálculo que alimenta la
     * pantalla, sobre el conjunto filtrado completo— y no de sumar las filas que se van
     * escribiendo, para que archivo y pantalla no puedan divergir.
     *
     * @param  list<\stdClass>  $filas
     */
    private function hojaJerarquica(array $filas): HojaInforme
    {
        $stats = $this->informe->subtotales($this->request);

        $porGrupo = [];
        foreach ($filas as $fila) {
            $porGrupo[$fila->categoria][$fila->subcategoria][] = $fila;
        }

        $datos = [];
        $destacadas = [];

        foreach ($stats['grupos'] as $grupo) {
            $datos[] = [$grupo['categoria']];
            $destacadas[] = count($datos);

            foreach ($grupo['subcategorias'] as $sub) {
                $datos[] = ['', $sub['subcategoria']];
                $destacadas[] = count($datos);

                foreach ($porGrupo[$grupo['categoria']][$sub['subcategoria']] ?? [] as $gasto) {
                    $datos[] = [
                        $gasto->id,
                        $this->fecha($gasto->fecha),
                        $gasto->descripcion,
                        $gasto->medio_pago,
                        (float) $gasto->total,
                    ];
                }

                $datos[] = ['', 'Subtotal '.$sub['subcategoria'], '', '', $sub['subtotal']];
                $destacadas[] = count($datos);
            }

            $datos[] = ['', 'Subtotal '.$grupo['categoria'], '', '', $grupo['subtotal']];
            $destacadas[] = count($datos);
        }

        $datos[] = [];
        $datos[] = ['', 'Gasto Total', '', '', $stats['gasto_total']];
        $destacadas[] = count($datos);

        return new HojaInforme(
            'Gastos',
            ['Id', 'Fecha / Concepto', 'Descripción', 'Medio de pago', 'Total'],
            $datos,
            $destacadas,
        );
    }

    /** @param  list<\stdClass>  $filas */
    private function hojaPlana(array $filas): HojaInforme
    {
        $datos = array_map(fn ($f) => [
            $f->id,
            $this->fecha($f->fecha),
            $f->categoria,
            $f->subcategoria,
            $f->descripcion,
            $f->medio_pago,
            (float) $f->total,
        ], $filas);

        return new HojaInforme(
            'Detalle plano',
            ['Id', 'Fecha', 'Categoría', 'Subcategoría', 'Descripción', 'Medio de pago', 'Total'],
            $datos,
        );
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor ? date('d/m/Y', strtotime((string) $valor)) : null;
    }
}
