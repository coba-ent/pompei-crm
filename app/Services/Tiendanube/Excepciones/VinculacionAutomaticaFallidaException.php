<?php

namespace App\Services\Tiendanube\Excepciones;

use RuntimeException;

/**
 * El recorrido del catálogo en vivo falló a mitad de la corrida de
 * `VinculadorAutomatico::ejecutar()` — se aborta sin crear ningún vínculo
 * (spec.md Assumptions, data-model.md).
 */
class VinculacionAutomaticaFallidaException extends RuntimeException {}
