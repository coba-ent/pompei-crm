<?php

namespace App\Services\Tiendanube\Excepciones;

use RuntimeException;

/**
 * El campo cifrado access_token no pudo descifrarse — típicamente porque
 * APP_KEY cambió. Ver data-model.md, "Notas de implementación para Laravel".
 */
class CredencialesIlegiblesException extends RuntimeException {}
