<?php

namespace App\Services\MercadoLibre\Bot;

use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreMensaje;

/**
 * Contrato para generar una sugerencia de respuesta con IA (spec 033, R6 de
 * research.md). Resuelto vía el Service Container — el proveedor concreto
 * (hoy OpenAI) queda aislado detrás de esta interfaz para poder reemplazarlo
 * sin tocar el Job ni los controllers.
 */
interface GeneradorDeSugerencias
{
    public function generar(MercadoLibreConversacion $conversacion, MercadoLibreMensaje $mensaje, string $instrucciones): string;
}
