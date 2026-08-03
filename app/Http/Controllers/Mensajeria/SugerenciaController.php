<?php

namespace App\Http\Controllers\Mensajeria;

use App\Http\Controllers\Controller;
use App\Jobs\GenerarSugerenciaMercadoLibre;
use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreMensaje;
use Illuminate\Http\JsonResponse;

/**
 * Generación de sugerencias bajo demanda (spec 033, US2, FR-006) — usado
 * cuando el switch está apagado, o para repedir una sugerencia con error.
 */
class SugerenciaController extends Controller
{
    public function store(MercadoLibreConversacion $conversacion): JsonResponse
    {
        // reorder() necesario — ver comentario en EnvioRespuestaMercadoLibre::enviar() (mismo bug).
        $mensaje = $conversacion->mensajes()->where('origen', 'comprador')->reorder('enviado_en', 'desc')->first();

        if (! $mensaje instanceof MercadoLibreMensaje) {
            return response()->json([
                'ok' => false,
                'message' => 'La conversación no tiene ningún mensaje del comprador para sugerir una respuesta.',
            ], 422);
        }

        GenerarSugerenciaMercadoLibre::dispatch($mensaje);

        return response()->json(['ok' => true, 'estado' => 'generando'], 202);
    }
}
