<?php

namespace App\Services\Informes\Contador;

/**
 * Asunto y cuerpo del correo al contador (spec 087, FR-004/FR-013/FR-014). Aislado de `PaqueteContador`
 * porque es puro texto: se testea sin correo de por medio, y porque el cuerpo **lista los adjuntos
 * reales** (FR-013) — necesita `listar()`, no `generar()`, para no pagar el costo de generar archivos
 * sólo para redactar el texto.
 */
class CuerpoCorreoContador
{
    /** FR-004: "Información de {nombre del negocio}". */
    public function asunto(string $nombreNegocio): string
    {
        return "Información de {$nombreNegocio}";
    }

    /**
     * FR-013/FR-014: nombra al destinatario implícitamente por el período, indica {@see Periodo::textoPeriodo()}
     * y lista los archivos que `PaqueteContador::listar()` previó para este envío.
     *
     * @param  array<int, string>  $archivos
     */
    public function cuerpo(Periodo $periodo, array $archivos): string
    {
        $lista = implode("\n", array_map(fn (string $a) => "- {$a}", $archivos));

        return "Hola, te enviamos la información {$periodo->textoPeriodo()}.\n\nArchivos adjuntos:\n{$lista}\n\nSaludos.";
    }
}
