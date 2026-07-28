<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/**
 * La conexión no pudo asegurarse (renovación irrecuperable, credenciales de
 * aplicación inválidas, o fallo transitorio agotados los reintentos). El
 * mensaje ya está traducido a lenguaje comprensible para el usuario.
 */
class ConexionCaidaException extends RuntimeException {}
