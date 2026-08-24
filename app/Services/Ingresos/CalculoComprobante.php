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
 *
 * Desde la spec 075 cada ítem sale además con `costo_unitario`: el costo del producto **vigente
 * en este momento**, congelado en la línea para que el CMV del Informe de Ventas no se mueva
 * cuando después cambie la ficha del producto. Se emite para todos los comprobantes que pasan por
 * acá, pero sólo `venta_items` y `nota_credito_debito_items` tienen la columna; en
 * `presupuesto_items` y `compra_items` la clave la descarta el mass assignment (no es `$fillable`),
 * que es el comportamiento buscado: un presupuesto no congela costo, recién lo hace la venta que
 * se genera a partir de él.
 */
class CalculoComprobante
{
    /**
     * @param  array<int, array{producto_id?: int|null, descripcion: string, cantidad: float|string, precio_unitario: float|string, descuento_pct?: float|string|null, iva_pct?: string|null, costo_unitario?: float|string|null}>  $items
     * @param  array<int, array{tipo: string, concepto: string, monto: float|string}>  $conceptos
     * @return array{items: array, subtotal_sin_descuento: float, descuento: float, subtotal_con_descuento: float, total: float}
     */
    public function calcular(
        array $items,
        string $descuentoGeneralTipo,
        float|string|null $descuentoGeneralValor,
        array $conceptos = []
    ): array {
        $descuentoGeneralValor = (float) ($descuentoGeneralValor ?? 0);
        $costosVigentes = $this->costosVigentes($items);

        if ($descuentoGeneralTipo === 'monto') {
            $subtotalBruto = 0.0;
            foreach ($items as $item) {
                $cantidad = (float) $item['cantidad'];
                $precioUnitario = (float) $item['precio_unitario'];
                $descuentoPct = (float) ($item['descuento_pct'] ?? 0);
                $bruto = $cantidad * $precioUnitario;
                $subtotalBruto += round($bruto - ($bruto * $descuentoPct / 100), 2);
            }

            $descuentoGeneralPct = $subtotalBruto > 0
                ? min(100, ($descuentoGeneralValor / $subtotalBruto) * 100)
                : 0;
        } else {
            $descuentoGeneralPct = $descuentoGeneralValor;
        }

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
                'costo_unitario' => $this->costoCongelado($item, $costosVigentes),
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

    /**
     * Costos vigentes de todos los productos del comprobante, en **una sola** query.
     *
     * Se resuelve por lote y no producto por producto dentro del loop: una venta de 60 líneas
     * haría 60 consultas extra en cada alta y en cada edición.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, float|null>
     */
    private function costosVigentes(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (! empty($item['producto_id'])) {
                $ids[] = (int) $item['producto_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return Producto::whereIn('id', array_unique($ids))
            ->pluck('costo', 'id')
            ->map(fn ($costo) => $costo === null ? null : (float) $costo)
            ->all();
    }

    /**
     * Costo a congelar en una línea (spec 075, `data-model.md §1`).
     *
     * Devuelve **`0`, nunca `null`**, cuando la línea no tiene producto o el producto no tiene
     * costo cargado: en el informe `0` significa "costo congelado que vale cero" —que es lo que
     * muestra Contagram para esos casos— mientras que `null` significaría "sin congelar" y haría
     * que la línea cayera al promedio ponderado de compras. No son intercambiables.
     *
     * Si la línea ya trae un `costo_unitario` resuelto (edición que conserva el costo anterior,
     * conversión de una orden externa), se respeta tal cual.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, float|null>  $costosVigentes
     */
    private function costoCongelado(array $item, array $costosVigentes): float
    {
        if (array_key_exists('costo_unitario', $item) && $item['costo_unitario'] !== null) {
            return round((float) $item['costo_unitario'], 2);
        }

        $productoId = $item['producto_id'] ?? null;

        if (empty($productoId)) {
            return 0.0;
        }

        return round((float) ($costosVigentes[(int) $productoId] ?? 0), 2);
    }
}
