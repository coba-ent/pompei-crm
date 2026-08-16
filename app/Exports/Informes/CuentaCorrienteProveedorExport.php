<?php

namespace App\Exports\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel de Cuenta Corriente Proveedores (spec 067, US4): dos hojas — el aging por proveedor
 * (hoja formateada, con su fila de totales) y el detalle plano de Movimientos.
 */
class CuentaCorrienteProveedorExport implements WithMultipleSheets
{
    private const CHUNK = 1000;

    /** @param  Collection<int, array<string, mixed>>  $saldos */
    public function __construct(private Collection $saldos, private Builder $movimientos) {}

    public function sheets(): array
    {
        return [$this->hojaSaldos(), $this->hojaMovimientos()];
    }

    private function hojaSaldos(): HojaInforme
    {
        $columnas = ['a_vencer', 'vencido_0_30', 'vencido_31_60', 'vencido_61_90', 'vencido_mas_90', 'total'];

        $datos = $this->saldos->map(fn (array $f) => array_merge(
            [$f['proveedor_nombre']],
            array_map(fn (string $c) => (float) $f[$c], $columnas),
        ))->values()->all();

        $datos[] = [];
        $datos[] = array_merge(
            ['Total'],
            array_map(fn (string $c) => round((float) $this->saldos->sum($c), 2), $columnas),
        );

        return new HojaInforme(
            'Saldos Proveedores',
            ['Proveedor', 'A Vencer', 'Vencido 0 y 30', 'Vencido 31 y 60', 'Vencido 61 y 90', 'Vencido >90', 'Total'],
            $datos,
            [count($datos)],
        );
    }

    private function hojaMovimientos(): HojaInforme
    {
        $etiquetas = [
            'compra' => 'Compra',
            'pago' => 'Pago',
            'nota_credito' => 'Nota de Crédito',
            'nota_debito' => 'Nota de Débito',
            'saldo_inicial' => 'Saldo Inicial',
        ];

        $datos = [];

        $this->movimientos
            ->leftJoin('proveedores', 'proveedores.id', '=', 'mov.proveedor_id')
            ->orderBy('mov.fecha_emision')
            ->orderBy('mov.id')
            ->select('mov.*', 'proveedores.nombre as proveedor')
            ->chunk(self::CHUNK, function ($chunk) use (&$datos, $etiquetas) {
                foreach ($chunk as $m) {
                    $datos[] = [
                        $m->id,
                        $m->fecha_emision ? date('d/m/Y', strtotime((string) $m->fecha_emision)) : null,
                        $m->proveedor,
                        $etiquetas[$m->operacion] ?? $m->operacion,
                        $m->categoria,
                        $m->total_compra === null ? null : (float) $m->total_compra,
                        $m->pagado === null ? null : (float) $m->pagado,
                        $m->a_pagar === null ? null : (float) $m->a_pagar,
                        $m->nro_comprobante,
                        $m->medio_pago,
                        $m->descripcion,
                    ];
                }
            });

        return new HojaInforme(
            'Movimientos',
            ['Id', 'Emisión', 'Proveedor', 'Operación', 'Categoría', 'Total Compra', 'Pagado',
                'A Pagar', 'N° de Comprobante', 'Medio de Pago', 'Descripción'],
            $datos,
        );
    }
}
