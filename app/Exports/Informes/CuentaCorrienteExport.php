<?php

namespace App\Exports\Informes;

use Illuminate\Support\Collection;
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

        $datos[] = array_merge(
            ['Total'],
            array_map(fn (string $c) => round((float) $this->saldos->sum($c), 2), $columnas),
        );

        return new HojaInforme(
            'Cuenta Corriente Clientes',
            ['Cliente', 'A Vencer', '0 y 30', '31 y 60', '61 y 90', '>90', 'Total'],
            $datos,
            [count($datos)],
        );
    }
}
