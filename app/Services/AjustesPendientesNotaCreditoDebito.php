<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\Venta;

/**
 * Cantidad pendiente de ajuste por producto en un comprobante (Venta o Compra), spec 045
 * data-model.md "Regla derivada": cantidad facturada menos lo ya ajustado por NC/ND previas
 * no eliminadas de ese mismo comprobante para ese producto.
 */
class AjustesPendientesNotaCreditoDebito
{
    public function pendiente(Venta|Compra $comprobante, int $productoId): float
    {
        $facturada = (float) $comprobante->items()
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        $yaAjustada = (float) $comprobante->notasCreditoDebito()
            ->with('items')
            ->get()
            ->flatMap(fn ($nota) => $nota->items)
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        return round($facturada - $yaAjustada, 3);
    }

    /**
     * @return array<int, array{producto_id:int, descripcion:string, pendiente:float}>
     */
    public function itemsDisponibles(Venta|Compra $comprobante): array
    {
        return $comprobante->items()
            ->whereNotNull('producto_id')
            ->get()
            ->groupBy('producto_id')
            ->map(function ($items, $productoId) use ($comprobante) {
                return [
                    'producto_id' => (int) $productoId,
                    'descripcion' => $items->first()->descripcion,
                    'pendiente' => $this->pendiente($comprobante, (int) $productoId),
                ];
            })
            ->filter(fn ($item) => $item['pendiente'] > 0)
            ->values()
            ->all();
    }
}
