<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ABM del catálogo "Tipo de Producto" (Contagram: seleccionar / "Crear Tipo de
 * Producto" / renombrar / eliminar desde el modal del producto).
 */
class TipoProductoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_producto', 'nombre')],
        ]);

        $tipo = TipoProducto::create(['nombre' => $datos['nombre'], 'activo' => true]);

        return response()->json(['ok' => true, 'tipo' => $tipo, 'mensaje' => 'Tipo de producto creado.']);
    }

    public function update(Request $request, TipoProducto $tipo): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_producto', 'nombre')->ignore($tipo->id)],
        ]);

        $tipo->update(['nombre' => $datos['nombre']]);

        return response()->json(['ok' => true, 'tipo' => $tipo, 'mensaje' => 'Tipo renombrado.']);
    }

    public function destroy(TipoProducto $tipo): JsonResponse
    {
        $tipo->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Tipo eliminado.']);
    }
}
