<?php

namespace Tests\Feature\Informes;

use App\Models\NotaCreditoDebito;
use App\Models\NotaCreditoDebitoItem;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Http\Request;

/**
 * Armado de ventas y notas para los tests del Informe de Ventas (spec 068).
 *
 * Graba los totales del comprobante **coherentes con sus ítems**, igual que el alta real: si el
 * helper mintiera ahí, los tests de la ecuación de KPIs pasarían con datos que la aplicación
 * nunca produce.
 */
trait ArmaVentas
{
    /**
     * `costo_unitario` se manda **sólo si la línea lo declara**: el default tiene que seguir
     * siendo `NULL` (línea sin costo congelado), que es el estado de las ventas históricas y lo
     * que activa el fallback del CMV (spec 075).
     *
     * @param  list<array{producto_id?: int|null, descripcion?: string, cantidad: float, precio: float, iva_pct?: string|null, costo_unitario?: float|null}>  $lineas
     */
    protected function venta(array $lineas, array $atributos = []): Venta
    {
        $neto = 0.0;
        $conIva = 0.0;

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;
            $neto += $subtotal;
            $conIva += $subtotal * (1 + $pct / 100);
        }

        $venta = Venta::factory()->create(array_merge([
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => round($neto, 2),
            'subtotal_con_descuento' => round($neto, 2),
            'total' => round($conIva, 2),
        ], $atributos));

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;

            $atributosItem = [
                'venta_id' => $venta->id,
                'producto_id' => $linea['producto_id'] ?? null,
                'descripcion' => $linea['descripcion'] ?? 'Ítem',
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $linea['precio'],
                'iva_pct' => $linea['iva_pct'] ?? null,
                'subtotal' => round($subtotal, 2),
                'subtotal_con_iva' => round($subtotal * (1 + $pct / 100), 2),
            ];

            if (array_key_exists('costo_unitario', $linea)) {
                $atributosItem['costo_unitario'] = $linea['costo_unitario'];
            }

            VentaItem::create($atributosItem);
        }

        return $venta;
    }

    /**
     * Nota de crédito o débito sobre una venta, con sus ítems.
     *
     * @param  list<array{producto_id?: int|null, cantidad: float, precio: float, costo_unitario?: float|null, origen?: string}>  $lineas
     */
    protected function nota(?Venta $venta, string $tipo, array $lineas, array $atributos = []): NotaCreditoDebito
    {
        $monto = 0.0;

        foreach ($lineas as $linea) {
            $monto += $linea['cantidad'] * $linea['precio'];
        }

        $nota = NotaCreditoDebito::factory()->create(array_merge([
            'venta_id' => $venta?->id,
            'tipo' => $tipo,
            'fecha_emision' => '2026-08-12',
            'monto' => round($monto, 2),
            'tipo_comprobante' => 'B',
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999), 8, '0', STR_PAD_LEFT),
        ], $atributos));

        foreach ($lineas as $linea) {
            $atributosItem = [
                'nota_credito_debito_id' => $nota->id,
                'producto_id' => $linea['producto_id'] ?? null,
                'cantidad' => $linea['cantidad'],
                'precio' => $linea['precio'],
                'origen' => $linea['origen'] ?? 'venta_original',
            ];

            if (array_key_exists('costo_unitario', $linea)) {
                $atributosItem['costo_unitario'] = $linea['costo_unitario'];
            }

            NotaCreditoDebitoItem::create($atributosItem);
        }

        return $nota;
    }

    protected function request(array $params = []): Request
    {
        return Request::create('/informes/ventas', 'GET', $params);
    }
}
