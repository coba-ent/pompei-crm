<?php

namespace App\Enums\MercadoLibre;

/**
 * Estado de conversión de una orden de Mercado Libre dentro del CRM (FR-007a,
 * data-model.md §1). Derivado y persistido: se recalcula en cada
 * sincronización y en cada intento de conversión (plan.md §3).
 */
enum EstadoConversion: string
{
    case PendientePago = 'pendiente_pago';
    case Lista = 'lista';
    case RequiereAtencion = 'requiere_atencion';
    case Convertida = 'convertida';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendientePago => 'Pendiente de pago',
            self::Lista => 'Lista para convertir',
            self::RequiereAtencion => 'Requiere atención',
            self::Convertida => 'Convertida',
            self::Cancelada => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendientePago => 'secondary',
            self::Lista => 'success',
            self::RequiereAtencion => 'warning',
            self::Convertida => 'primary',
            self::Cancelada => 'danger',
        };
    }

    public function habilitaCrearVenta(): bool
    {
        return $this === self::Lista;
    }

    /** ¿Es válida la transición de este estado hacia $destino? (data-model.md §1). */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesValidas(), true);
    }

    /**
     * @return array<int, self>
     */
    private function transicionesValidas(): array
    {
        return match ($this) {
            self::PendientePago => [self::Lista, self::Cancelada],
            self::Lista => [self::RequiereAtencion, self::Convertida, self::Cancelada],
            self::RequiereAtencion => [self::Lista, self::Cancelada],
            self::Convertida => [self::Cancelada],
            self::Cancelada => [],
        };
    }
}
