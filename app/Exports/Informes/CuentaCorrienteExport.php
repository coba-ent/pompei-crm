<?php

namespace App\Exports\Informes;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel de Saldos de Clientes: una única hoja "Cuenta Corriente Clientes" con el aging por
 * cliente y la fila de totales al pie — espejo exacto del export real de Contagram (una sola
 * hoja, sin el detalle de Movimientos, que Contagram no incluye en este botón).
 */
class CuentaCorrienteExport implements WithMultipleSheets
{
    /** @param  Collection<int, array<string, mixed>>  $saldos */
    public function __construct(private Collection $saldos) {}

    public function sheets(): array
    {
        return [$this->hojaSaldos()];
    }

    private function hojaSaldos(): HojaInforme
    {
        $columnas = ['a_vencer', 'vencido_0_30', 'vencido_31_60', 'vencido_61_90', 'vencido_mas_90', 'total'];

        $datos = $this->saldos->map(fn (array $f) => array_merge(
            [$f['cliente_nombre']],
            array_map(fn (string $c) => (float) $f[$c], $columnas),
        ))->values()->all();

        // Los totales van como SUBTOTAL(109) —la única función de Excel que ignora las filas
        // ocultas por un autofiltro— así que al filtrar por un cliente el total pasa a ser el de
        // ese cliente. Acá es seguro para TODAS las columnas: la hoja es una fila por cliente, sin
        // subtotales intercalados, y el total que se venía escribiendo era exactamente la suma de
        // esas mismas columnas.
        $ultimaFilaDatos = count($datos) + 1;   // +1 por la fila de encabezados

        $datos[] = array_merge(
            ['Total'],
            array_map(
                fn (int $i) => $ultimaFilaDatos >= 2
                    ? '=SUBTOTAL(109,'.Coordinate::stringFromColumnIndex($i + 2).'2:'
                        .Coordinate::stringFromColumnIndex($i + 2).$ultimaFilaDatos.')'
                    : 0,
                array_keys($columnas),
            ),
        );

        return new HojaInforme(
            'Cuenta Corriente Clientes',
            ['Cliente', 'A Vencer', '0 y 30', '31 y 60', '61 y 90', '>90', 'Total'],
            $datos,
            [count($datos)],
            conAutofiltro: true,
        );
    }
}
