<?php

namespace App\Services\Tiendanube\Excepciones;

use RuntimeException;

/**
 * El auto-registro del cliente OAuth (POST /register, RFC 7591) falló o el
 * servidor MCP no está disponible — edge case de spec.md 019.
 */
class RegistroClienteFallidoException extends RuntimeException {}
