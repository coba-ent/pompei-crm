<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Alta inline de categorías desde los formularios de Presupuesto/Venta/Otro Ingreso (CLAUDE.md §5). */
class CategoriaController extends Controller
{
    /** "Crear Categoría de Ingreso" (modal Otro Ingreso). */
    public function storeIngreso(Request $request): JsonResponse
    {
        return $this->crear($request, 'ingreso');
    }

    /** "Crear Categoría de ventas" (formulario Presupuesto/Venta). */
    public function storeVenta(Request $request): JsonResponse
    {
        return $this->crear($request, 'venta');
    }

    /** "Crear Categoría de Compras" (formulario Compra / alta de Proveedor). */
    public function storeCompra(Request $request): JsonResponse
    {
        return $this->crear($request, 'compra');
    }

    /** "Crear Categoría de Gasto" (modal Gasto, raíz del árbol). */
    public function storeGasto(Request $request): JsonResponse
    {
        return $this->crear($request, 'gasto');
    }

    /** "Crear Subcategoría" de Gasto (hija de la categoría elegida). */
    public function storeSubcategoriaGasto(Request $request, Categoria $categoria): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255|unique:categorias,nombre',
        ]);

        $subcategoria = Categoria::create([
            'tipo' => 'gasto',
            'categoria_padre_id' => $categoria->id,
            'nombre' => $datos['nombre'],
            'activo' => true,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Subcategoría creada correctamente.',
            'categoria' => $subcategoria,
        ], 201);
    }

    private function crear(Request $request, string $tipo): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255|unique:categorias,nombre',
        ]);

        $categoria = Categoria::create([
            'tipo' => $tipo,
            'nombre' => $datos['nombre'],
            'activo' => true,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Categoría creada correctamente.',
            'categoria' => $categoria,
        ], 201);
    }
}
