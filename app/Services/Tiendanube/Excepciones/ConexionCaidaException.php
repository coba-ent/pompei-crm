<?php

namespace App\Services\Tiendanube\Excepciones;

use RuntimeException;

/**
 * La conexión no pudo asegurarse (credencial inválida/revocada, o fallo
 * transitorio agotados los reintentos). El mensaje ya está traducido a
 * lenguaje comprensible para el usuario.
 */
class ConexionCaidaException extends RuntimeException {}
