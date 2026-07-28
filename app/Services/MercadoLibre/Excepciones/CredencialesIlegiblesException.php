<?php

namespace App\Services\MercadoLibre\Excepciones;

use RuntimeException;

/**
 * Un campo cifrado (client_secret, access_token, refresh_token) no pudo
 * descifrarse — típicamente porque APP_KEY cambió. Ver data-model.md,
 * "Notas de implementación para Laravel".
 */
class CredencialesIlegiblesException extends RuntimeException {}
