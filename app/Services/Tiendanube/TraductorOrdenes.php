<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConversion;

/**
 * Único punto que interpreta el formato externo de una orden de Tiendanube
 * (plan.md §1): ningún controlador, job ni modelo lee directamente los campos
 * crudos de la tool `list_orders`. Mapea a los arrays de atributos listos para
 * `TiendanubeOrden`/`TiendanubeOrdenItem` (data-model.md), y es la única línea
 * de defensa contra la duplicación con el canal Mercado Libre integrado
 * (FR-012a, research.md R2 corregido: no existe filtro `channels` en la
 * consulta, así que el descarte tiene que pasar por acá).
 */
class TraductorOrdenes
{
    public const MONEDA_NEGOCIO = 'ARS';

    /** Valor exacto de `storefront` que identifica el canal Mercado Libre integrado (FR-012a). */
    private const STOREFRONT_MELI = 'meli';

    private const PAYMENT_STATUS_CANCELADA = ['refunded', 'partially_refunded', 'voided'];

    /**
     * @param  array<string, mixed>  $ordenCruda  Resultado de la tool `list_orders`.
     * @return array<string, mixed>|null Atributos para `TiendanubeOrden` (sin `estado_conversion`, que
     *                                    decide el evaluador), o null si la orden debe descartarse
     *                                    por completo (FR-012a: storefront=meli exacto).
     */
    public function traducirOrden(array $ordenCruda): ?array
    {
        $storefront = $ordenCruda['storefront'] ?? null;

        // FR-012a (corrección post-019): única capa de defensa — la consulta no admite excluirlo.
        // La ausencia del dato, o cualquier valor distinto de "meli" exacto, NO se excluye.
        if ($storefront === self::STOREFRONT_MELI) {
            return null;
        }

        $customer = $ordenCruda['customer'] ?? [];
        $totalNodo = $ordenCruda['total'] ?? [];
        $completedAt = $ordenCruda['completed_at'] ?? null;

        return [
            'tn_order_id' => (int) $ordenCruda['id'],
            'status' => (string) ($ordenCruda['status'] ?? ''),
            'payment_status' => (string) ($ordenCruda['payment_status'] ?? ''),
            'fulfillment_status' => $ordenCruda['fulfillment_status'] ?? null,
            'fecha_creada' => $completedAt,
            'fecha_cerrada' => $completedAt,
            'total' => (float) ($totalNodo['amount'] ?? 0),
            'moneda' => (string) ($totalNodo['currency'] ?? self::MONEDA_NEGOCIO),
            'storefront' => $storefront,
            'tn_customer_id' => isset($customer['id']) ? (int) $customer['id'] : null,
            'comprador_email' => $customer['email'] ?? null,
            'comprador_nombre' => $customer['name'] ?? null,
            'billing_document_number' => $customer['cpf_cnpj'] ?? null,
            'payload' => $ordenCruda,
        ];
    }

    /**
     * @param  array<string, mixed>  $ordenCruda
     * @return array<int, array<string, mixed>> Atributos para `TiendanubeOrdenItem` (sin `tn_orden_id`).
     */
    public function traducirItems(array $ordenCruda): array
    {
        $items = [];

        foreach ($ordenCruda['items'] ?? [] as $item) {
            $cantidad = (float) ($item['quantity'] ?? 0);
            $precioNodo = $item['price'] ?? [];
            $precioUnitario = (float) ($precioNodo['amount'] ?? 0);

            $items[] = [
                'tn_product_id' => (int) ($item['product_id'] ?? 0),
                'variant_id' => (int) ($item['variant_id'] ?? 0),
                'nombre_producto' => (string) ($item['name'] ?? ''),
                'nombre_variante' => $this->nombreVariante($item['variant_values'] ?? []),
                'sku' => $item['sku'] ?? null,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'total_linea' => round($cantidad * $precioUnitario, 2),
            ];
        }

        return $items;
    }

    /**
     * Estado de conversión "base" derivado únicamente de `status`+`payment_status`
     * (FR-007a): `Convertida` (venta_id ya presente) y `RequiereAtencion` (precondiciones
     * de negocio) NO se deciden acá — los resuelve `EvaluadorConvertibilidad`, que llama
     * a este método sólo para el punto de partida.
     *
     * Nota de interpretación: la tabla de FR-007a describe las condiciones con
     * `status = open`, pero una orden `closed` (ciclo de vida normal tras
     * despacharse) igual de paga y no cancelada debe poder convertirse — se
     * trata `open` y `closed` de forma equivalente acá, reservando `Cancelada`
     * estrictamente para `status = cancelled` o el conjunto de `payment_status`
     * de reembolso/anulación (a verificar contra más órdenes reales).
     */
    public function estadoBaseDesdeCrudo(string $status, string $paymentStatus): EstadoConversion
    {
        if ($status === 'cancelled') {
            return EstadoConversion::Cancelada;
        }

        if (in_array($paymentStatus, self::PAYMENT_STATUS_CANCELADA, true)) {
            return EstadoConversion::Cancelada;
        }

        if ($paymentStatus === 'paid') {
            return EstadoConversion::Lista;
        }

        return EstadoConversion::PendientePago;
    }

    /** FR-030d: la conversión sólo admite la moneda del negocio. */
    public function monedaValida(string $moneda): bool
    {
        return $moneda === self::MONEDA_NEGOCIO;
    }

    /**
     * Arma el nombre de variante concatenando `variant_values` (corrección
     * post-019: no existe un campo de nombre de variante suelto). Vacío para
     * la variante virtual de un producto sin variantes reales.
     */
    private function nombreVariante(array $variantValues): ?string
    {
        $valores = array_filter(array_map(fn ($v) => is_string($v) ? $v : ($v['value'] ?? null), $variantValues));

        return $valores ? implode(' / ', $valores) : null;
    }
}
