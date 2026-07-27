<?php

namespace App\Http\Controllers;

use App\Models\Localidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo geográfico para los selects linkeados (país fijo: Argentina).
 */
class GeoController extends Controller
{
    /** Localidades de una provincia (por nombre), ordenadas alfabéticamente. */
    public function localidades(Request $request): JsonResponse
    {
        $provincia = trim((string) $request->input('provincia', ''));

        if ($provincia === '') {
            return response()->json(['localidades' => []]);
        }

        $localidades = Localidad::whereHas('provincia', fn ($q) => $q->where('nombre', $provincia))
            ->orderBy('nombre')
            ->pluck('nombre');

        return response()->json(['localidades' => $localidades]);
    }
}
