<?php

namespace App\Services\Arca\Excepciones;

/** ARCA respondió pero rechazó la solicitud (datos fiscales inválidos, observaciones), o la validación local previa a WSFEv1 falló (FR-009, FR-010). */
class ArcaRechazoException extends ArcaException
{
    public function __construct(string $motivo)
    {
        parent::__construct($motivo);
    }
}
