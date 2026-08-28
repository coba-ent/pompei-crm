<?php

namespace App\Services\Informes\Contador;

/**
 * Verifica el tamaño total de los adjuntos **antes** de enviar (spec 087, FR-022/SC-006): avisar
 * de antemano en vez de que el envío falle a mitad de camino contra el servidor SMTP.
 *
 * El límite se toma del servidor de correo configurado (spec Assumptions) vía `MAIL_LIMITE_ADJUNTOS_MB`
 * — no hay un valor único válido para cualquier proveedor SMTP, así que se deja configurable con un
 * default conservador (25 MB, el límite típico de Gmail/la mayoría de los proveedores).
 */
class VerificadorTamanoAdjuntos
{
    /** @param  array<string, string>  $rutas  [nombre => ruta local] */
    public function verificar(array $rutas): void
    {
        $limiteBytes = (int) config('mail.limite_adjuntos_mb', 25) * 1024 * 1024;

        $total = array_sum(array_map(fn (string $ruta) => file_exists($ruta) ? filesize($ruta) : 0, $rutas));

        if ($total > $limiteBytes) {
            $totalMb = round($total / 1024 / 1024, 1);
            $limiteMb = round($limiteBytes / 1024 / 1024, 1);

            throw new \RuntimeException(
                "Los adjuntos pesan {$totalMb} MB, más de lo que admite el servidor de correo ({$limiteMb} MB). Reducí el período o desmarcá algún adjunto."
            );
        }
    }
}
