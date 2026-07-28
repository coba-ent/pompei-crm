<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/** La sincronización de órdenes falló contra la API (mensaje ya traducido para el usuario). */
class SincronizacionFallidaException extends RuntimeException {}
