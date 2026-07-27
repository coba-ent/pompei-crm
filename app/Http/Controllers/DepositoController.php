<?php

namespace App\Http\Controllers;

use App\Models\Deposito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ABM de Depósitos (Configuración & Ajustes → Depósitos). Espejo de
 * ListaPrecioController/TipoProductoController, con protección de eliminación
 * vía Deposito::tieneOperaciones().
 */
class DepositoController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-depositos';

        return view('configuracion.depositos', compact('CurrentPage'));
    }

    /** Lista completa de depósitos, sin paginado (catálogo chico). */
    public function data(): JsonResponse
    {
        return response()->json([
            'data' => Deposito::orderBy('nombre')->get(['id', 'nombre', 'activo']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('depositos', 'nombre')],
        ]);

        $deposito = Deposito::create(['nombre' => $datos['nombre'], 'activo' => true]);

        return response()->json(['ok' => true, 'deposito' => $deposito, 'mensaje' => 'Depósito creado.']);
    }

    public function update(Request $request, Deposito $deposito): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('depositos', 'nombre')->ignore($deposito->id)],
        ]);

        $deposito->update(['nombre' => $datos['nombre']]);

        return response()->json(['ok' => true, 'deposito' => $deposito, 'mensaje' => 'Depósito renombrado.']);
    }

    /** Alternar activo/inactivo (baja lógica). */
    public function estado(Deposito $deposito): JsonResponse
    {
        $deposito->activo = ! $deposito->activo;
        $deposito->save();

        return response()->json([
            'ok' => true,
            'activo' => $deposito->activo,
            'mensaje' => $deposito->activo ? 'Depósito activado.' : 'Depósito desactivado.',
        ]);
    }

    /** Eliminar físicamente (sólo si no tiene stock/movimientos asociados). */
    public function destroy(Deposito $deposito): JsonResponse
    {
        if ($deposito->tieneOperaciones()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Sólo puede inactivarse: el depósito tiene stock o movimientos asociados.',
            ], 409);
        }

        $deposito->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Depósito eliminado.']);
    }
}
