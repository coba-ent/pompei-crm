<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\MovimientoStock;
use App\Models\Venta;

/**
 * Detecta cambios de stock elegibles para empujar hacia Mercado Libre (spec 013,
 * research.md R1) y hacia Tiendanube (spec 018, research.md R1): único punto por
 * el que pasa cualquier movimiento de stock del CRM (Ventas, ajustes,
 * transferencias), sin importar el módulo que lo originó. Marca el vínculo como
 * pendiente; no envía nada — eso lo hacen los `SincronizadorStock` de cada
 * integración. Ambas ramas son independientes entre sí (plan.md §"Enfoque
 * técnico" punto 1 de la spec 018).
 */
class MovimientoStockObserver
{
    public function created(MovimientoStock $movimiento): void
    {
        $this->ramaMercadoLibre($movimiento);
        $this->ramaTiendanube($movimiento);
    }

    private function ramaMercadoLibre(MovimientoStock $movimiento): void
    {
        $depositoMl = MercadoLibreConfiguracion::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoMl->id) {
            return;
        }

        if ($this->esConversionDeOrden($movimiento, 'mercadolibre')) {
            return;
        }

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)->first();

        if (! $vinculo) {
            return;
        }

        $vinculo->update(['stock_pendiente' => true]);
    }

    /** Rama Tiendanube (spec 018, FR-001/FR-002/FR-005): mismo esqueleto que la de Mercado Libre. */
    private function ramaTiendanube(MovimientoStock $movimiento): void
    {
        $depositoTn = TiendanubeConexionRest::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoTn->id) {
            return;
        }

        if ($this->esConversionDeOrden($movimiento, 'tiendanube')) {
            return;
        }

        $vinculo = TiendanubeVarianteProducto::where('producto_id', $movimiento->producto_id)->first();

        if (! $vinculo) {
            return;
        }

        $vinculo->update(['stock_pendiente' => true]);
    }

    /**
     * R2 — evita el bucle: un movimiento generado por la conversión de una orden
     * de la integración indicada en Venta (FR-046 de la 012/017) no debe
     * disparar un envío de vuelta, porque esa integración ya sabe ese cambio.
     */
    private function esConversionDeOrden(MovimientoStock $movimiento, string $origen): bool
    {
        if ($movimiento->origen_type !== Venta::class) {
            return false;
        }

        $venta = Venta::find($movimiento->origen_id);

        return $venta?->origen === $origen;
    }
}
