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

    /**
     * Cabecera del comprobante de origen para precargar el alta de una NC/ND (spec 095).
     *
     * La nota nace como espejo del comprobante: hasta ahora sólo se precargaban los ítems y el
     * resto de la cabecera quedaba vacía, así que una nota sobre una venta con descuento general
     * nacía por el importe SIN descuento — de más. Acá se arma lo que falta.
     *
     * @return array{tipoComprobante:?string, descuentoGeneralTipo:?string, descuentoGeneralPct:?float, descuentoGeneralMonto:?float, fechaEmision:?string, fechaVencimiento:?string, servicioDesde:?string, servicioHasta:?string, tercero:?array{id:int, nombre:string}, categoria:?array{id:int, nombre:string}, conceptos:array<int, array{tipo:string, concepto:string, monto:float}>}
     */
    public function cabeceraComprobante(Venta|Compra $comprobante): array
    {
        $esVenta = $comprobante instanceof Venta;

        // FR-005: cada fecha usa la del comprobante y, si no está cargada, cae en la de emisión.
        $fechaEmision = $this->aIso($comprobante->fecha_emision);
        $respaldo = fn ($fecha) => $this->aIso($fecha) ?? $fechaEmision;

        // Ventas y Compras nombran distinto la misma fecha (cobro vs. pago).
        $vencimiento = $esVenta ? $comprobante->fecha_vto_cobro : $comprobante->fecha_vto_pago;

        $tercero = $esVenta ? $comprobante->cliente : $comprobante->proveedor;
        $categoria = $comprobante->categoria;

        // FR-002: el descuento general se hereda CON su modalidad. En modo monto se pasa el importe
        // tal cual: convertirlo a un porcentaje equivalente introduciría un error de redondeo en un
        // documento fiscal.
        $descuentoTipo = $comprobante->descuento_general_tipo ?: 'porcentaje';
        $descuentoPct = $comprobante->descuento_general_pct;
        $descuentoMonto = $comprobante->descuento_general_monto;

        return [
            // FR-004: si el comprobante no tiene tipo, se manda null y el campo queda vacío.
            // No se infiere ninguno: una nota con el tipo cruzado no se arregla editándola.
            'tipoComprobante' => $comprobante->tipo_comprobante ?: null,
            'descuentoGeneralTipo' => $descuentoTipo,
            'descuentoGeneralPct' => $descuentoTipo === 'porcentaje' && $descuentoPct !== null
                ? (float) $descuentoPct
                : null,
            'descuentoGeneralMonto' => $descuentoTipo === 'monto' && $descuentoMonto !== null
                ? (float) $descuentoMonto
                : null,
            'fechaEmision' => $fechaEmision,
            'fechaVencimiento' => $respaldo($vencimiento),
            'servicioDesde' => $respaldo($comprobante->servicio_desde),
            'servicioHasta' => $respaldo($comprobante->servicio_hasta),
            'tercero' => $tercero
                ? ['id' => (int) $tercero->id, 'nombre' => (string) $tercero->nombre]
                : null,
            'categoria' => $categoria
                ? ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->nombre]
                : null,
            // FR-007: los conceptos del comprobante ya vienen con la misma forma
            // {tipo, concepto, monto} que la nota usa en su columna JSON `impuestos`.
            'conceptos' => $comprobante->conceptos
                ->map(fn ($c) => [
                    'tipo' => (string) $c->tipo,
                    'concepto' => (string) $c->concepto,
                    'monto' => (float) $c->monto,
                ])
                ->values()
                ->all(),
        ];
    }

    /** Fechas hacia el front siempre en ISO (`YYYY-MM-DD`); el helper AppFecha las muestra en dd/mm/aaaa. */
    private function aIso($fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return $fecha instanceof \DateTimeInterface
            ? $fecha->format('Y-m-d')
            : \Illuminate\Support\Carbon::parse($fecha)->format('Y-m-d');
    }
}
