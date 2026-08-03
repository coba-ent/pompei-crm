<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mensajeria\GuardarConfiguracionBotMercadoLibreRequest;
use App\Models\Integraciones\MercadoLibreBotConfiguracion;
use Illuminate\Http\JsonResponse;

/**
 * Configuración & Ajustes → Bot de Mercado Libre (spec 033, US1): tono/
 * instrucciones que sigue la IA al redactar sugerencias. El activo/inactivo
 * del bot vive en Funciones Avanzadas (`FuncionAvanzadaController`), no acá.
 */
class MercadoLibreBotConfiguracionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-mercadolibre-bot';
        $configuracion = MercadoLibreBotConfiguracion::actual();

        return view('configuracion.mercadolibre.bot', compact('CurrentPage', 'configuracion'));
    }

    public function guardar(GuardarConfiguracionBotMercadoLibreRequest $request): JsonResponse
    {
        $configuracion = MercadoLibreBotConfiguracion::actual();
        $configuracion->update([
            'instrucciones_tono' => $request->validated('instrucciones_tono'),
            'actualizada_por' => $request->user()->id,
            'actualizada_en' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Configuración del bot guardada.',
        ]);
    }
}
