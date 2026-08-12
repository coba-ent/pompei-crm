<?php

namespace App\Enums\MercadoLibre;

/**
 * Estado de la orden normalizado desde el `status` crudo de Mercado Libre
 * (research.md §R2, data-model.md §1). El valor crudo del proveedor se
 * conserva aparte en `ml_ordenes.estado_ml` para no perder información ante
 * estados nuevos que el proveedor agregue.
 */
enum EstadoOrden: string
{
    case Pendiente = 'pendiente';
    case Pagada = 'pagada';
    case Cancelada = 'cancelada';
    case ReembolsoParcial = 'reembolso_parcial';
    case Otro = 'otro';

    /**
     * Mapea el `status` crudo de la API (`paid`, `cancelled`, etc.) al enum normalizado.
     *
     * spec 063 / data-model.md §"EstadoOrden": `partially_refunded` deja de colapsarse
     * con `cancelled`/`pending_cancel` — antes eran indistinguibles. La mediación NO se
     * deriva de este estado: vive en `payments[].status` (ver TraductorOrdenes::estadoPagos()).
     */
    public static function desdeCrudo(string $statusCrudo): self
    {
        return match ($statusCrudo) {
            'paid' => self::Pagada,
            'confirmed', 'payment_required', 'payment_in_process', 'partially_paid' => self::Pendiente,
            'cancelled', 'pending_cancel' => self::Cancelada,
            'partially_refunded' => self::ReembolsoParcial,
            default => self::Otro,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de pago',
            self::Pagada => 'Pagada',
            self::Cancelada => 'Cancelada',
            self::ReembolsoParcial => 'Reembolso parcial',
            self::Otro => 'Otro',
        };
    }
}
