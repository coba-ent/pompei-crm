<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/**
 * El canje del código de autorización, o una acción sobre una autorización
 * pendiente, fue rechazado (state inválido/vencido, sitio incorrecto, error
 * traducido del proveedor). El mensaje ya está en español, listo para
 * mostrarse al usuario (SC-006) — el llamador nunca debe mostrar el error
 * crudo del proveedor.
 */
class VinculacionRechazadaException extends RuntimeException {}
