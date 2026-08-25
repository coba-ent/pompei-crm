<?php

namespace App\Exports\Informes;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Espejo de {@see MovimientosClientesExport} del lado Proveedores (spec 080, US2, FR-008): 33
 * columnas — sin "Vendedor", con "Sellos" agregada antes de "Total Compra" (siempre 0, FR-017).
 */
class MovimientosProveedoresExport implements WithMultipleSheets
{
    private const ENCABEZADOS = [
        'Id', 'Emisión', 'Proveedor', 'CUIT', 'Operación', 'Categoría', 'Medio de Pago', 'Descripción',
        'Tipo de Comprobante', 'Punto de Venta', 'N° de Comprobante', 'Aplicada en N° de Factura',
        'Fecha Factura Aplicada', 'Id Compra', 'Subtotal sin Descuento', 'Descuento en $',
        'Subtotal con Descuento', 'Importe Neto No Gravado', 'Importe Neto Gravado',
        'IVA - 2,5%', 'IVA - 5%', 'IVA - 10,5%', 'IVA - 21%', 'IVA - 27%', 'Exento', 'No Gravado',
        'Perc. IVA', 'Perc. IIBB', 'Imp. Internos', 'Imp. Municipales', 'Sellos', 'Total Compra', 'Pagado', 'A pagar',
    ];

    private const ETIQUETAS_OPERACION = [
        'compra' => 'Compra',
        'pago' => 'Pago',
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
            $m['proveedor'],
            $m['cuit'],
            self::ETIQUETAS_OPERACION[$m['operacion']] ?? $m['operacion'],
            $m['categoria'],
            $m['medio_pago'],
            $m['descripcion'],
            $m['tipo_comprobante'],
            $m['punto_venta'],
            $m['nro_comprobante'],
            $m['aplicada_nro_factura'],
            $m['fecha_factura_aplicada'],
            $m['id_compra'],
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
            $m['sellos'],
            $m['total_compra'],
            $m['pagado'],
            $m['a_pagar'],
        ])->values()->all();

        return new HojaInforme('Movimientos de Proveedores', self::ENCABEZADOS, $datos);
    }
}
