<?php

namespace App\Services\Informes\Contador;

/**
 * Casillas de contenido del envío al contador (spec 087, data-model §3). Invariante FR-020: al
 * menos una de Electrónicas/Manuales debe quedar tildada, validada **en el constructor** — así un
 * envío inválido es imposible de construir, con o sin validación de formulario.
 */
class OpcionesEnvio
{
    public function __construct(
        public readonly bool $incluyeElectronicas,
        public readonly bool $incluyeManuales,
        public readonly bool $incluyePdfs,
    ) {
        if (! $incluyeElectronicas && ! $incluyeManuales) {
            throw new \InvalidArgumentException(
                'Al menos una de "Facturas Electrónicas" o "Facturas Manuales" debe estar tildada: con ambas destildadas el libro IVA Ventas quedaría vacío.'
            );
        }
    }
}
