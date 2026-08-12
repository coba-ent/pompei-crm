<?php

namespace App\Http\Controllers;

use App\Models\Transportista;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transportista (spec 064): sólo alta al vuelo desde el remito, sin pantalla de ABM propia
 * (FR-021, FR-022, FR-023 — decisión de alcance).
 */
class TransportistaController extends Controller
{
    /** Opciones para el buscador Select2 del formulario de remito (FR-021). */
    public function opciones(Request $request): JsonResponse
    {
        $busqueda = (string) $request->query('q', '');

        $transportistas = Transportista::query()
            ->when($busqueda !== '', fn ($q) => $q->where('nombre', 'like', '%'.$busqueda.'%'))
            ->orderBy('nombre')
            ->limit(50)
            ->get(['id', 'nombre']);

        return response()->json(['data' => $transportistas]);
    }

    /** Alta al vuelo: reutiliza el existente si el nombre ya está, en vez de duplicarlo (FR-023). */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate(['nombre' => 'required|string|max:255']);

        $transportista = Transportista::firstOrCreate(['nombre' => $datos['nombre']]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Transportista creado correctamente.',
            'transportista' => $transportista,
        ], 201);
    }
}
