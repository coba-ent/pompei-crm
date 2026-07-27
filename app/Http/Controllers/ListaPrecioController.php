<?php

namespace App\Http\Controllers;

use App\Models\ListaPrecio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ABM del catálogo global de listas de precio (Contagram: "Agregar Lista de
 * Precios" / renombrar / eliminar desde Opciones Avanzadas del producto).
 */
class ListaPrecioController extends Controller
{
    /** Listas activas para poblar la sección de precios del modal de producto. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('listas_precio', 'nombre')],
        ]);

        $lista = ListaPrecio::create(['nombre' => $datos['nombre'], 'activo' => true]);

        return response()->json(['ok' => true, 'lista' => $lista, 'mensaje' => 'Lista de precios creada.']);
    }

    public function update(Request $request, ListaPrecio $lista): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('listas_precio', 'nombre')->ignore($lista->id)],
        ]);

        $lista->update(['nombre' => $datos['nombre']]);

        return response()->json(['ok' => true, 'lista' => $lista, 'mensaje' => 'Lista renombrada.']);
    }

    public function destroy(ListaPrecio $lista): JsonResponse
    {
        $lista->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Lista eliminada.']);
    }
}
