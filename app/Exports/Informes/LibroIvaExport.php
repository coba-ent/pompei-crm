<?php

namespace App\Exports\Informes;

use App\Services\Informes\LibroIvaQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel del Libro IVA (spec 077, US5): **una hoja**, con el bloque de totales del período arriba
 * y el detalle completo debajo, con las 19 columnas siempre presentes (FR-034) — research §D12.
 *
 * Reusa el mismo servicio de query que `data`/`stats`, así que los importes del archivo coinciden
 * al centavo con los de la pantalla (FR-033).
 */
class LibroIvaExport implements FromArray, WithTitle
{
    private const ENCABEZADOS = [
        'Id', 'Emisión', 'Tipo', 'N° de Comprobante', 'Cliente/Proveedor', 'CUIT/DNI', 'Condición de IVA',
        'Importe Neto No Gravado', 'Importe Neto Exento', 'Importe Neto Gravado',
        'IVA 2,5%', 'IVA 5%', 'IVA 10,5%', 'IVA 21%', 'IVA 27%',
        'Perc. IVA', 'Perc. IIBB', 'Imp. Internos', 'Imp. Municipales',
    ];

    public function __construct(
        private LibroIvaQuery $informe,
        private Request $request,
        private string $titulo,
    ) {}

    public function array(): array
    {
        $totales = $this->informe->totales($this->request);

        $filas = [
            [$this->titulo],
            [],
            ['No Gravados/Exentos', 'Gravados', 'IVA Total', 'Perc. IVA/IIBB', 'Total Facturado'],
            [
                $totales['no_gravados_exentos'], $totales['gravados'], $totales['iva_total'],
                $totales['perc_iva_iibb_total'], $totales['total_facturado'],
            ],
            [],
            self::ENCABEZADOS,
        ];

        foreach ($this->informe->detalle($this->request)->get() as $fila) {
            $filas[] = [
                $fila->id, (string) $fila->emision, $fila->tipo, $fila->nro_comprobante,
                $fila->contraparte, $fila->cuit, $fila->condicion_iva,
                (float) $fila->neto_no_gravado, (float) $fila->neto_exento, (float) $fila->neto_gravado,
                (float) $fila->iva_2_5, (float) $fila->iva_5, (float) $fila->iva_10_5, (float) $fila->iva_21, (float) $fila->iva_27,
                (float) $fila->perc_iva, (float) $fila->perc_iibb, (float) $fila->imp_internos, (float) $fila->imp_municipales,
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return $this->titulo;
    }
}
