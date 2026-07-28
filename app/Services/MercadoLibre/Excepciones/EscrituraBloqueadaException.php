<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/**
 * Reservada para llamadores que necesiten distinguir explícitamente un
 * bloqueo por modo sólo lectura de otros errores (ClienteMercadoLibre en sí
 * no la lanza: devuelve RespuestaMercadoLibre::bloqueada() sin excepción,
 * para que el flujo normal del caller no tenga que envolver cada llamada en
 * un try/catch).
 */
class EscrituraBloqueadaException extends RuntimeException {}
