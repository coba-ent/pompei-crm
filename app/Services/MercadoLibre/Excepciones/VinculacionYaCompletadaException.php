<?php

namespace App\Services\MercadoLibre\Excepciones;

/**
 * El retorno de autorización llegó dos veces (el usuario recargó la página de
 * vuelta). No es un error: la primera vez ya completó la vinculación y ésta
 * no debe romperse (FR-021). Se distingue de VinculacionRechazadaException
 * para que el controlador la muestre como informativa, no como error.
 */
class VinculacionYaCompletadaException extends VinculacionRechazadaException {}
