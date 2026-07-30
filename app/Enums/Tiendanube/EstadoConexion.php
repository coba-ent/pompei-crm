<?php

namespace App\Enums\Tiendanube;

/**
 * Estados de la conexión con Tiendanube (tn_configuracion.estado).
 *
 * `NoConfigurada` es un valor DERIVADO (spec 019 data-model.md §1): se calcula
 * cuando `access_token` está vacío, cubriendo tanto "nunca conectado" como
 * "desconectado" (FR-006) — no hay datos parciales sin `store_id` manual que
 * distingan un caso del otro. `Desconectada` queda como caso del enum sin uso
 * nuevo (no se vuelve a persistir desde spec 019 en adelante), conservado para
 * no romper datos históricos. Sin `PendienteConfirmacion` (research.md §R6):
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
