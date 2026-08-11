<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
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

        // Mercado Libre descuenta el stock **sólo de la publicación por la que se vendió**. Si el
        // producto tiene varias publicaciones (72 de 177 las tienen), las demás siguen ofreciendo
        // el stock viejo, así que hay que empujarles el cambio igual: saltear todas dejaba esas
        // publicaciones desfasadas para siempre, porque nadie las volvía a marcar.
        $yaDescontadas = $this->publicacionesDeLaOrdenMl($movimiento);

        MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)
            ->when($yaDescontadas !== [], fn ($q) => $q->whereNotIn('ml_item_id', $yaDescontadas))
            ->update(['stock_pendiente' => true]);
    }

    /** Rama Tiendanube (spec 018, FR-001/FR-002/FR-005): mismo esqueleto que la de Mercado Libre. */
    private function ramaTiendanube(MovimientoStock $movimiento): void
    {
        $depositoTn = TiendanubeConexionRest::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoTn->id) {
            return;
        }

        $yaDescontadas = $this->variantesDeLaOrdenTn($movimiento);

        TiendanubeVarianteProducto::where('producto_id', $movimiento->producto_id)
            ->when($yaDescontadas !== [], fn ($q) => $q->whereNotIn('variant_id', $yaDescontadas))
            ->update(['stock_pendiente' => true]);
    }

    /**
     * R2 — publicaciones que NO hay que volver a empujar: las que la propia orden ya descontó
     * del lado de Mercado Libre. Antes se salteaba el producto entero, y ahí estaba el problema
     * (ver arriba). Devuelve `[]` cuando el movimiento no vino de una orden de ML, o cuando no
     * se puede resolver qué publicación fue: en ese caso se marcan todas, que a lo sumo cuesta
     * un PUT redundante con el valor correcto — nunca un dato mal.
     *
     * @return array<string>
     */
    private function publicacionesDeLaOrdenMl(MovimientoStock $movimiento): array
    {
        $venta = $this->ventaDeOrigen($movimiento, 'mercadolibre');

        if ($venta === null || ! $venta->ml_order_id) {
            return [];
        }

        return MercadoLibreOrden::where('ml_order_id', $venta->ml_order_id)
            ->first()?->items()->pluck('ml_item_id')->all() ?? [];
    }

    /**
     * Equivalente de Tiendanube: las variantes que la orden ya descontó allá.
     *
     * @return array<string>
     */
    private function variantesDeLaOrdenTn(MovimientoStock $movimiento): array
    {
        $venta = $this->ventaDeOrigen($movimiento, 'tiendanube');

        if ($venta === null || ! $venta->tn_order_id) {
            return [];
        }

        return TiendanubeOrden::where('tn_order_id', $venta->tn_order_id)
            ->first()?->items()->pluck('variant_id')->all() ?? [];
    }

    /** La Venta que originó el movimiento, sólo si vino de la integración indicada. */
    private function ventaDeOrigen(MovimientoStock $movimiento, string $origen): ?Venta
    {
        if ($movimiento->origen_type !== Venta::class) {
            return null;
        }

        $venta = Venta::find($movimiento->origen_id);

        return $venta?->origen === $origen ? $venta : null;
    }
}
