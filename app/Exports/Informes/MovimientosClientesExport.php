<?php

namespace App\Exports\Informes;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel de Movimientos de Cuenta Corriente Clientes (spec 080, US2): una sola hoja "Movimientos de
 * Clientes" con las 34 columnas de data-model.md, en el orden exacto del contrato (FR-007). Los
 * importes fiscales (netos/IVA/percepciones) vienen ya calculados por
 * {@see \App\Services\Informes\MovimientosClientesQuery}, que reutiliza el motor del Libro IVA del
 * Contador (spec 077) — este export no calcula nada, sólo proyecta.
 */
class MovimientosClientesExport implements WithMultipleSheets
{
    private const ENCABEZADOS = [
        'Id', 'Emisión', 'Cliente', 'CUIT', 'Operación', 'Categoría', 'Medio de Cobro', 'Descripción',
        'Tipo de Comprobante', 'Punto de Venta', 'N° de Comprobante', 'Aplicada en N° de Factura',
        'Fecha Factura Aplicada', 'Id Venta', 'Vendedor', 'Subtotal sin Descuento', 'Descuento en $',
        'Subtotal con Descuento', 'Importe Neto No Gravado', 'Importe Neto Gravado',
        'IVA - 2,5%', 'IVA - 5%', 'IVA - 10,5%', 'IVA - 21%', 'IVA - 27%', 'Exento', 'No Gravado',
        'Perc. IVA', 'Perc. IIBB', 'Imp. Internos', 'Imp. Municipales', 'Total Venta', 'Cobrado', 'A cobrar',
    ];

    private const ETIQUETAS_OPERACION = [
        'venta' => 'Venta',
        'cobro' => 'Cobro',
        'nota_credito' => 'Nota de Crédito',
        'nota_debito' => 'Nota de Débito',
        'saldo_inicial' => 'Saldo Inicial',
    ];

    /** @param  Collection<int, array<string, mixed>>  $movimientos */
    public function __construct(private Collection $movimientos) {}

    public function sheets(): array
    {
        return [$this->hoja()];
    }

    private function hoja(): HojaInforme
    {
        $datos = $this->movimientos->map(fn (array $m) => [
            $m['id'],
            $m['emision'] ? date('d/m/Y', strtotime((string) $m['emision'])) : null,
            $m['cliente'],
            $m['cuit'],
            self::ETIQUETAS_OPERACION[$m['operacion']] ?? $m['operacion'],
            $m['categoria'],
            $m['medio_cobro'],
            $m['descripcion'],
            $m['tipo_comprobante'],
            $m['punto_venta'],
            $m['nro_comprobante'],
            $m['aplicada_nro_factura'],
            $m['fecha_factura_aplicada'],
            $m['id_venta'],
            $m['vendedor'],
            $m['subtotal_sin_descuento'],
            $m['descuento'],
            $m['subtotal_con_descuento'],
            $m['neto_no_gravado'],
            $m['neto_gravado'],
            $m['iva_2_5'],
            $m['iva_5'],
            $m['iva_10_5'],
            $m['iva_21'],
            $m['iva_27'],
            $m['exento'],
            $m['no_gravado'],
            $m['perc_iva'],
            $m['perc_iibb'],
            $m['imp_internos'],
            $m['imp_municipales'],
            $m['total_venta'],
            $m['cobrado'],
            $m['a_cobrar'],
        ])->values()->all();

        return new HojaInforme('Movimientos de Clientes', self::ENCABEZADOS, $datos);
    }
}
