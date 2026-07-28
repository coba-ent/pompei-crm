<?php

namespace App\Enums\MercadoLibre;

/**
 * Estados de la vinculación con Mercado Libre (ml_cuentas.estado).
 *
 * `NoConfigurada` es un valor DERIVADO: se calcula cuando `ml_configuracion` está
 * incompleta (sin client_id/client_secret) y nunca se persiste en la columna `estado`
 * de `ml_cuentas` — ver data-model.md §3.
 */
enum EstadoConexion: string
{
    case NoConfigurada = 'no_configurada';
    case Desconectada = 'desconectada';
    case Conectada = 'conectada';
    case PendienteConfirmacion = 'pendiente_confirmacion';
    case Caida = 'caida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NoConfigurada => 'No configurada',
            self::Desconectada => 'Desconectada',
            self::Conectada => 'Conectada',
            self::PendienteConfirmacion => 'Pendiente de confirmación',
            self::Caida => 'Caída',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoConfigurada => 'secondary',
            self::Desconectada => 'secondary',
            self::Conectada => 'success',
            self::PendienteConfirmacion => 'warning',
            self::Caida => 'danger',
        };
    }
}
