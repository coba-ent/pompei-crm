<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Dataset del pivot para el Informe de Compras (spec 069).
 *
 * Espeja a {@see VentasPivotDataset}. La diferencia de fondo la pone {@see DimensionesPivot}:
 * Compras no tiene vendedor, y donde Ventas cruza por cliente, Compras cruza por proveedor — no
 * se inventan dimensiones que su modelo no tenga.
 */
class ComprasPivotDataset extends PivotDataset
{
    public function __construct(DimensionesPivot $catalogo, private ComprasInformeQuery $consulta)
    {
        parent::__construct($catalogo);
    }

    protected function informe(): string
    {
        return 'compras';
    }

    protected function consultaBase(Request $peticion): Builder
    {
        return $this->consulta->detalle($peticion);
    }
}
