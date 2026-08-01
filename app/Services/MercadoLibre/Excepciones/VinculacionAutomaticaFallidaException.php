<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/**
 * El recorrido del catálogo en vivo (scan search o multiget) falló a mitad de
 * la corrida de `VinculadorAutomatico::ejecutar()` — se aborta sin crear
 * ningún vínculo (spec.md Assumptions, data-model.md).
 */
class VinculacionAutomaticaFallidaException extends RuntimeException {}
