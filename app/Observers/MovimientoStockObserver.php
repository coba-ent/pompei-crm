<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\MovimientoStock;
use App\Models\Venta;

/**
 * Detecta cambios de stock elegibles para empujar hacia Mercado Libre (spec 013,
 * research.md R1): único punto por el que pasa cualquier movimiento de stock del
 * CRM (Ventas, ajustes, transferencias), sin importar el módulo que lo originó.
 * Marca el vínculo como pendiente; no envía nada — eso lo hace SincronizadorStock.
 */
class MovimientoStockObserver
{
    public function created(MovimientoStock $movimiento): void
    {
        $depositoMl = MercadoLibreConfiguracion::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoMl->id) {
            return;
        }

        if ($this->esConversionDeOrdenMercadoLibre($movimiento)) {
            return;
        }

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)->first();

        if (! $vinculo) {
            return;
        }

        $vinculo->update(['stock_pendiente' => true]);
    }

    /**
     * R2 — evita el bucle: un movimiento generado por la conversión de una orden
     * de Mercado Libre en Venta (spec 012, FR-046) no debe disparar un envío de
     * vuelta, porque Mercado Libre ya sabe ese cambio.
     */
    private function esConversionDeOrdenMercadoLibre(MovimientoStock $movimiento): bool
    {
        if ($movimiento->origen_type !== Venta::class) {
            return false;
        }

        $venta = Venta::find($movimiento->origen_id);

        return $venta?->origen === 'mercadolibre';
    }
}
