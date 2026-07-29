<?php

namespace App\Enums\Tiendanube;

/**
 * Estados de la conexión con Tiendanube (tn_configuracion.estado).
 *
 * `NoConfigurada` es un valor DERIVADO: se calcula cuando `tn_configuracion` está
 * incompleta (sin store_id/access_token) y nunca se persiste así en la columna
 * `estado` — ver data-model.md §1. Sin `PendienteConfirmacion` (research.md §R6):
 * no existe pantalla de autorización externa donde pueda aparecer "otra cuenta".
 */
enum EstadoConexion: string
{
    case NoConfigurada = 'no_configurada';
    case Desconectada = 'desconectada';
    case Conectada = 'conectada';
    case Caida = 'caida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NoConfigurada => 'No configurada',
            self::Desconectada => 'Desconectada',
            self::Conectada => 'Conectada',
            self::Caida => 'Caída',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoConfigurada => 'secondary',
            self::Desconectada => 'secondary',
            self::Conectada => 'success',
            self::Caida => 'danger',
        };
    }
}
