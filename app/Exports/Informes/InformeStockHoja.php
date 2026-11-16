<?php

namespace App\Exports\Informes;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja del Excel del Informe de Stock.
 *
 * No usa `HojaInforme` a propósito: ésa ajusta el ancho de cada columna al contenido
 * (`setAutoSize`), que obliga a PhpSpreadsheet a medir celda por celda. Con los volúmenes de
 * este informe —9.000 movimientos en 90 días, 40.000 en el histórico— eso solo duplicaba el
 * tiempo de generación y lo acercaba al límite de 30 s de php-fpm. Acá los anchos son fijos.
 *
 * `WithStrictNullComparison` por el mismo motivo que en `HojaInforme`: sin él, una cantidad o
 * un saldo en 0 no se escribe en el archivo.
 */
class InformeStockHoja implements FromArray, WithColumnWidths, WithStrictNullComparison, WithStyles, WithTitle
{
    public const ENCABEZADOS = [
        'ID', 'Fecha', 'Usuario', 'Operación', 'Descripción', 'Tipo de Factura', 'N° de Factura',
        'Código', 'Producto', 'Tipo de Producto', 'Proveedor', 'Depósito', 'Cantidad', 'Saldo Stock',
    ];

    /** @param  list<list<mixed>>  $filas */
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Stock';
    }

    public function array(): array
    {
        return array_merge([self::ENCABEZADOS], $this->filas);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 9, 'B' => 17, 'C' => 16, 'D' => 13, 'E' => 45, 'F' => 9, 'G' => 16,
            'H' => 18, 'I' => 55, 'J' => 18, 'K' => 22, 'L' => 14, 'M' => 10, 'N' => 12,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:N1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B2B2B']],
        ]);

        $sheet->setAutoFilter('A1:N'.(count($this->filas) + 1));
        $sheet->freezePane('A2');

        return [];
    }
}
