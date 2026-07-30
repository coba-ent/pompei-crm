<?php

namespace App\Http\Controllers;

use App\Models\Vendedor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ABM inline de Vendedores (spec 020, FR-012) desde el select de Venta, Presupuesto y las
 * configuraciones de Tiendanube/MercadoLibre — calcado de CategoriaController, sin la
 * complejidad de tipo/es_sistema/jerarquía que Vendedor no tiene (research.md R4).
 */
class VendedorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255|unique:vendedores,nombre',
        ]);

        $vendedor = Vendedor::create(['nombre' => $datos['nombre']]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Vendedor creado correctamente.',
            'vendedor' => $vendedor,
        ], 201);
    }

    public function update(Request $request, Vendedor $vendedor): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('vendedores', 'nombre')->ignore($vendedor->id)],
        ]);

        $vendedor->update(['nombre' => $datos['nombre']]);

        return response()->json(['ok' => true, 'mensaje' => 'Vendedor renombrado.', 'vendedor' => $vendedor]);
    }

    public function destroy(Vendedor $vendedor): JsonResponse
    {
        try {
            $vendedor->delete();
        } catch (QueryException $e) {
            return response()->json(['ok' => false, 'mensaje' => 'No se puede eliminar: está en uso.'], 422);
        }

        return response()->json(['ok' => true, 'mensaje' => 'Vendedor eliminado.']);
    }
}
