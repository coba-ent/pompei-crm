<?php

namespace App\Services\Tiendanube\Excepciones;

use RuntimeException;

/** La sincronización de órdenes de Tiendanube falló contra la API (mensaje ya traducido para el usuario). */
class SincronizacionFallidaException extends RuntimeException {}
