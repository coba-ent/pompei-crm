<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Dataset del pivot para el Informe de Ventas (spec 069).
 *
 * Se apoya en `VentasInformeQuery::detalle()` y NO en una consulta propia: así el cruce sale del
 * mismo conjunto que la tabla de detalle y los KPIs de la pantalla. Si mañana cambia un filtro o
 * la regla de qué comprobantes entran, cambia en un solo lugar y los tres quedan alineados.
 */
class VentasPivotDataset extends PivotDataset
{
    public function __construct(DimensionesPivot $catalogo, private VentasInformeQuery $consulta)
    {
        parent::__construct($catalogo);
    }

    protected function informe(): string
    {
        return 'ventas';
    }

    protected function consultaBase(Request $peticion): Builder
    {
        return $this->consulta->detalle($peticion);
    }

    protected function columnasCalculadasEnPhp(): array
    {
        return ['cmv_total_producto'];
    }

    /**
     * "Costo Total Vendido": el CMV del producto sumado en TODO el período filtrado, repetido en
     * cada línea de ese producto (pedido del cliente, 03/11/2026).
     *
     * Las dimensiones del pivot agrupan, no suman: con el CMV de cada línea, un producto vendido
     * en 40 ventas de cantidades distintas se partía en 40 filas. Con el total ya sumado el valor
     * es único por producto y queda una sola fila, al lado de la venta.
     *
     * Es el total del período, no de cada columna: si el cruce se abre por mes, este valor sigue
     * siendo el del rango entero.
     */
    protected function completarFilas(array $filas): array
    {
        $clave = fn (array $f) => $f['producto_id'] !== null ? 'id:'.$f['producto_id'] : 'nombre:'.$f['producto'];

        $totales = [];
        foreach ($filas as $fila) {
            $totales[$clave($fila)] = ($totales[$clave($fila)] ?? 0) + (float) $fila['cmv_total'];
        }

        return array_map(function (array $fila) use ($totales, $clave) {
            $fila['cmv_total_producto'] = round($totales[$clave($fila)], 2);

            return $fila;
        }, $filas);
    }
}
