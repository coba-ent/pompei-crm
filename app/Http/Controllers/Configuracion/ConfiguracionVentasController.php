<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionVentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuración & Ajustes → Ventas (spec 043): defaults globales de "Crear Venta" (fila única). */
class ConfiguracionVentasController extends Controller
{
    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:vendedores,id'],
            'lista_precio_id' => ['nullable', 'integer', 'exists:listas_precio,id'],
            'tipo_comprobante' => ['nullable', 'in:A,B,C,E'],
            'dias_vto_cobro' => ['nullable', 'integer', 'min:0'],
        ]);

        ConfiguracionVentas::query()->updateOrCreate([], $datos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Configuración de Ventas guardada.',
        ]);
    }
}
