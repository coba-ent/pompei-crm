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
    case Otro = 'otro';

    /** Mapea el `status` crudo de la API (`paid`, `cancelled`, etc.) al enum normalizado. */
    public static function desdeCrudo(string $statusCrudo): self
    {
        return match ($statusCrudo) {
            'paid' => self::Pagada,
            'confirmed', 'payment_required', 'payment_in_process', 'partially_paid' => self::Pendiente,
            'cancelled', 'pending_cancel', 'partially_refunded' => self::Cancelada,
            default => self::Otro,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de pago',
            self::Pagada => 'Pagada',
            self::Cancelada => 'Cancelada',
            self::Otro => 'Otro',
        };
    }
}
