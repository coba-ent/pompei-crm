<?php

namespace App\Services\Ingresos;

use App\Models\Producto;

/**
 * Cálculo puro de totales de un comprobante (Presupuesto o Venta), compartido
 * entre ambos (plan.md — Structure Decision). Sin efectos secundarios: recibe
 * ítems + descuento general + conceptos extra y devuelve los totales
 * derivados, exactamente como se congelan en `total`/`subtotal_*` (data-model.md
 * §Cálculos clave). El servidor SIEMPRE recalcula al guardar — nunca confía en
 * los totales enviados por el cliente (research.md §7).
 */
class CalculoComprobante
{
    /**
     * @param  array<int, array{producto_id?: int|null, descripcion: string, cantidad: float|string, precio_unitario: float|string, descuento_pct?: float|string|null, iva_pct?: string|null}>  $items
     * @param  array<int, array{tipo: string, concepto: string, monto: float|string}>  $conceptos
     * @return array{items: array, subtotal_sin_descuento: float, descuento: float, subtotal_con_descuento: float, total: float}
     */
    public function calcular(array $items, float|string|null $descuentoGeneralPct, array $conceptos = []): array
    {
        $descuentoGeneralPct = (float) ($descuentoGeneralPct ?? 0);
        $factor = 1 - ($descuentoGeneralPct / 100);

        $itemsCalculados = [];
        $subtotalSinDescuento = 0.0;
        $subtotalConDescuento = 0.0;
        $totalConIva = 0.0;

        foreach ($items as $item) {
            $cantidad = (float) $item['cantidad'];
            $precioUnitario = (float) $item['precio_unitario'];
            $descuentoPct = (float) ($item['descuento_pct'] ?? 0);
            $ivaPct = Producto::porcentajeIva($item['iva_pct'] ?? null);

            $bruto = $cantidad * $precioUnitario;
            $subtotalLinea = round($bruto - ($bruto * $descuentoPct / 100), 2);
            $subtotalConIvaLinea = round($subtotalLinea + ($subtotalLinea * $ivaPct / 100), 2);

            $subtotalFinal = round($subtotalLinea * $factor, 2);
            $subtotalConIvaFinal = round($subtotalConIvaLinea * $factor, 2);

            $itemsCalculados[] = [
                'producto_id' => $item['producto_id'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'descuento_pct' => $item['descuento_pct'] ?? null,
                'iva_pct' => $item['iva_pct'] ?? null,
                'subtotal' => $subtotalFinal,
                'subtotal_con_iva' => $subtotalConIvaFinal,
            ];

            $subtotalSinDescuento += $subtotalLinea;
            $subtotalConDescuento += $subtotalFinal;
            $totalConIva += $subtotalConIvaFinal;
        }

        $subtotalConDescuento = round($subtotalConDescuento, 2);
        $descuento = round($subtotalSinDescuento - $subtotalConDescuento, 2);

        $totalConceptos = 0.0;
        foreach ($conceptos as $concepto) {
            $totalConceptos += (float) $concepto['monto'];
        }

        $total = round($totalConIva + $totalConceptos, 2);

        return [
            'items' => $itemsCalculados,
            'subtotal_sin_descuento' => round($subtotalSinDescuento, 2),
            'descuento' => $descuento,
            'subtotal_con_descuento' => $subtotalConDescuento,
            'total' => $total,
        ];
    }
}
