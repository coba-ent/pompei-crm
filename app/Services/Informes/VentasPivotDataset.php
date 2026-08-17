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
}
