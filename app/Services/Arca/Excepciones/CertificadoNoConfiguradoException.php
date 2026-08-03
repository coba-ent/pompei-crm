<?php

namespace App\Services\Arca\Excepciones;

/**
 * No hay certificado fiscal activo o Punto de Venta por defecto configurado (FR-014).
 * Quien la capture debe aplicar el fallback local sin validez fiscal.
 */
class CertificadoNoConfiguradoException extends ArcaException {}
