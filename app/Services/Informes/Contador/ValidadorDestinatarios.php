<?php

namespace App\Services\Informes\Contador;

/**
 * Valida la lista de destinatarios del envío al contador (spec 087, FR-002/FR-017): varias
 * direcciones separadas por coma, al menos una, todas válidas, señalando **cuál** falla.
 */
class ValidadorDestinatarios
{
    /**
     * @return array<int, string> direcciones normalizadas (sin espacios)
     *
     * @throws \InvalidArgumentException con el mensaje señalando la dirección inválida
     */
    public function parsear(string $destinatarios): array
    {
        $direcciones = array_values(array_filter(array_map('trim', explode(',', $destinatarios))));

        if ($direcciones === []) {
            throw new \InvalidArgumentException('Ingresá al menos un destinatario.');
        }

        foreach ($direcciones as $direccion) {
            if (! filter_var($direccion, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("La dirección \"{$direccion}\" no es un mail válido.");
            }
        }

        return $direcciones;
    }
}
