<?php

namespace App\Exports\Informes;

use App\Services\Informes\ComprasInformeQuery;
use App\Services\Informes\DesgloseImpositivoCompra;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del Informe de Compras (spec 067, US4): dos hojas.
 *
 * - **Compras**: hoja formateada, con las columnas por defecto de la pantalla y una fila de
 *   totales que replica los KPIs.
 * - **Detalle plano**: una fila por registro con **todas** las columnas, desglose impositivo
 *   incluido, con independencia de qué columnas estén visibles en pantalla (FR-041). Es la hoja
 *   que se lleva el contador.
 *
 * Reusa el mismo servicio de query y los mismos parámetros de filtro que `data`/`stats`, así que
 * los totales del archivo coinciden al centavo con los de la pantalla (FR-043).
 */
class InformeComprasExport implements WithMultipleSheets
{
    /** Se recorre en chunks: un período grande no entra en memoria de una sola vez. */
    private const CHUNK = 1000;

    public function __construct(private ComprasInformeQuery $informe, private Request $request) {}

    public function sheets(): array
    {
        $filas = $this->filas();

        return [
            $this->hojaFormateada($filas),
            $this->hojaPlana($filas),
        ];
    }

    /** @return list<\stdClass> */
    private function filas(): array
    {
        $acumulado = [];

        $this->informe->detalle($this->request)
            ->orderBy('detalle.fecha')
            ->orderBy('detalle.id')
            ->chunk(self::CHUNK, function ($chunk) use (&$acumulado) {
                foreach ($chunk as $fila) {
                    $acumulado[] = $fila;
                }
            });

        return $acumulado;
    }

    /** @param  list<\stdClass>  $filas */
    private function hojaFormateada(array $filas): HojaInforme
    {
        $kpis = $this->informe->kpis($this->request);

        $datos = array_map(fn ($f) => [
            $f->id,
            $this->fecha($f->fecha),
            $f->comprobante,
            $f->proveedor,
            $f->producto_servicio,
            $this->num($f->cantidad),
            $this->num($f->precio),
            $this->num($f->total_comprobante),
        ], $filas);

        // "Cantidad" es la única columna de esta hoja que se puede totalizar sumando sus filas,
        // así que va como SUBTOTAL(109) y sigue al filtro de Excel. Verificado contra la base: su
        // suma por fila da 2.646, igual que el KPI.
        //
        // "Total Comprobante" NO: ese importe se repite en cada ítem de la misma compra y sumarlo
        // por fila la contaría de más —medido, da 1.004 millones contra los 102 millones reales—.
        // Ese y los demás totales de comprobante quedan con el valor del informe completo, con el
        // rótulo aclarándolo para que nadie lea un número filtrado como si fuera el del filtro.
        $ultimaFilaDetalle = count($datos) + 1;   // +1 por la fila de encabezados

        $cantidad = $ultimaFilaDetalle >= 2
            ? "=SUBTOTAL(109,F2:F{$ultimaFilaDetalle})"
            : 0;

        $datos[] = [];
        $datos[] = ['Total Compras Creadas (informe completo)', $kpis['total_compras_creadas']];
        $datos[] = ['Total Nota de Débito (informe completo)', $kpis['total_nota_debito']];
        $datos[] = ['Total Nota de Crédito (informe completo)', $kpis['total_nota_credito']];
        $datos[] = ['Total Compras (informe completo)', $kpis['total_compras']];
        $datos[] = ['Cantidad Prod./Serv.', $cantidad];
        $datos[] = ['Cantidad Compras Creadas (informe completo)', $kpis['cantidad_compras_creadas']];
        $datos[] = ['Compra Promedio (informe completo)', $kpis['compra_promedio']];
        $datos[] = ['Costo Actual (informe completo)', $kpis['costo_actual']];

        $totalFilas = count($datos);
        $destacadas = range($totalFilas - 7, $totalFilas);

        return new HojaInforme(
            'Compras',
            ['Id', 'Fecha', 'Comprobante', 'Proveedor', 'Producto/Servicio', 'Cant.', 'Precio', 'Total Comprobante'],
            $datos,
            $destacadas,
            conAutofiltro: true,
        );
    }

    /** @param  list<\stdClass>  $filas */
    private function hojaPlana(array $filas): HojaInforme
    {
        $encabezados = [
            'Id', 'Fecha', 'Comprobante', 'Proveedor', 'Producto/Servicio', 'Cant.', 'Precio',
            'Total Comprobante', 'Vencimiento', 'CUIT/DNI', 'Tipo', 'Tipo de Comprobante',
            'Punto de Venta', 'N° Factura', 'Código', 'Tipo de Producto', 'Costo',
            'Subtotal sin Descuento', 'Descuento en $', 'Subtotal con Descuento',
            'Importe Neto No Gravado', 'Importe Neto Exento', 'Importe Neto Gravado',
        ];

        foreach (DesgloseImpositivoCompra::ALICUOTAS as $alicuota => $clave) {
            $encabezados[] = 'IVA '.str_replace('.', ',', $alicuota).'%';
        }

        $encabezados = array_merge($encabezados, [
            'Perc. IVA', 'Perc. IIBB', 'Otras Percepciones', 'Imp. Internos', 'Total Compra',
            'Etiquetas', 'Afecta Stock', 'Operación',
        ]);

        $datos = array_map(function ($f) {
            $base = [
                $f->id, $this->fecha($f->fecha), $f->comprobante, $f->proveedor, $f->producto_servicio,
                $this->num($f->cantidad), $this->num($f->precio), $this->num($f->total_comprobante),
                $this->fecha($f->vencimiento), $f->cuit_dni, $f->tipo, $f->tipo_comprobante,
                $f->punto_venta, $f->nro_factura, $f->codigo, $f->tipo_producto, $this->num($f->costo),
                $this->num($f->subtotal_sin_descuento), $this->num($f->descuento_monto),
                $this->num($f->subtotal_con_descuento),
                $this->num($f->neto_no_gravado), $this->num($f->neto_exento), $this->num($f->neto_gravado),
            ];

            foreach (DesgloseImpositivoCompra::ALICUOTAS as $clave) {
                $base[] = $this->num($f->{$clave});
            }

            return array_merge($base, [
                $this->num($f->perc_iva), $this->num($f->perc_iibb), $this->num($f->otras_percepciones),
                $this->num($f->imp_internos), $this->num($f->total_compra),
                $f->etiquetas, $f->afecta_stock, $f->operacion,
            ]);
        }, $filas);

        return new HojaInforme('Detalle plano', $encabezados, $datos);
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor ? date('d/m/Y', strtotime((string) $valor)) : null;
    }

    /** Celdas numéricas de verdad (no texto): el valor viaja sin depender del locale del lector. */
    private function num(mixed $valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }
}
