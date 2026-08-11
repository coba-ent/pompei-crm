<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;

/**
 * Cantidad pendiente de ajuste por producto en un comprobante (Venta o Compra), spec 045
 * data-model.md "Regla derivada": cantidad facturada menos lo ya ajustado por NC/ND previas
 * no eliminadas de ese mismo comprobante para ese producto.
 */
class AjustesPendientesNotaCreditoDebito
{
    /** @param NotaCreditoDebito|null $excluir Nota en edición (FR-005): se excluye del "ya ajustado". */
    public function pendiente(Venta|Compra $comprobante, int $productoId, ?NotaCreditoDebito $excluir = null): float
    {
        $facturada = (float) $comprobante->items()
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        $yaAjustada = (float) $comprobante->notasCreditoDebito()
            ->with('items')
            ->get()
            ->reject(fn ($nota) => $excluir && $nota->id === $excluir->id)
            ->flatMap(fn ($nota) => $nota->items)
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        return round($facturada - $yaAjustada, 3);
    }

    /**
     * @return array<int, array{producto_id:int, descripcion:string, pendiente:float, precio:float, descuento_pct:float, iva_pct:?string}>
     */
    public function itemsDisponibles(Venta|Compra $comprobante): array
    {
        return $comprobante->items()
            ->whereNotNull('producto_id')
            ->get()
            ->groupBy('producto_id')
            ->map(function ($items, $productoId) use ($comprobante) {
                $primero = $items->first();

                return [
                    'producto_id' => (int) $productoId,
                    'descripcion' => $primero->descripcion,
                    'pendiente' => $this->pendiente($comprobante, (int) $productoId),
                    // Precarga la página completa de NC/ND (spec 059) con el precio/descuento/IVA
                    // que ya tenía el comprobante de origen para ese producto — el usuario puede
                    // editarlos igual si la nota corresponde a un monto distinto.
                    'precio' => (float) $primero->precio_unitario,
                    'descuento_pct' => (float) ($primero->descuento_pct ?? 0),
                    'iva_pct' => $primero->iva_pct,
                ];
            })
            ->filter(fn ($item) => $item['pendiente'] > 0)
            ->values()
            ->all();
    }
}
