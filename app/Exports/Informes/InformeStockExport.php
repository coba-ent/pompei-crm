<?php

namespace App\Exports\Informes;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del Informe de Stock: una fila por movimiento, con los filtros de la pantalla.
 *
 * Columnas calcadas del export de Contagram (relevado el 14/08/2026, docs/Informe-Modulo-Informes-
 * 2026-08-14 §11.6) más "Depósito", porque en el CRM el saldo se lleva por producto y depósito y
 * sin esa columna dos filas seguidas del mismo producto parecen contradecirse.
 *
 * El "ID" es el del documento que originó el movimiento (venta, compra o nota), igual que la
 * columna ID de la pantalla; los ajustes sin documento lo dejan vacío.
 */
class InformeStockExport implements WithMultipleSheets
{
    private const CHUNK = 2000;

    private const OPERACIONES = [
        'entrada' => 'Entrada',
        'salida' => 'Salida',
        'ajuste' => 'Ajuste',
        'transferencia' => 'Transferencia',
    ];

    /** @param  Builder  $query  ya filtrada y ordenada por el controlador */
    public function __construct(private Builder $query) {}

    public function sheets(): array
    {
        $filas = [];

        // cursor() y no get(): sin rango de fechas el informe recorre el histórico entero.
        foreach ($this->query->cursor() as $mov) {
            $filas[] = [
                $mov->origen_id && in_array($mov->origen_type, [Venta::class, Compra::class, NotaCreditoDebito::class], true)
                    ? (int) $mov->origen_id
                    : null,
                $mov->fecha ? date('d/m/Y H:i', strtotime((string) $mov->fecha)) : null,
                $mov->usuario,
                self::OPERACIONES[$mov->tipo] ?? $mov->tipo,
                $mov->detalle,
                $mov->tipo_factura,
                $mov->nro_factura,
                $mov->producto_codigo,
                $mov->producto,
                $mov->tipo_producto,
                $mov->proveedor_producto,
                $mov->deposito,
                (float) $mov->cantidad,
                (float) $mov->stock_saldo,
            ];
        }

        return [new InformeStockHoja($filas)];
    }
}
