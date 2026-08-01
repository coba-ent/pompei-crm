<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Enums\Tiendanube\EstadoConexion as EstadoConexionTiendanube;
use App\Http\Controllers\Controller;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\TiendanubeConexionRest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pantalla "Funciones Avanzadas" (Configuración & Ajustes → Funciones Avanzadas, spec 011).
 * Lista de 10 tarjetas con toggle Sí/No persistido — ver docs/documentacion_principal_crm.md §5.1.
 */
class FuncionAvanzadaController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-funciones-avanzadas';
        $funciones = FuncionAvanzada::ordenadas()->get();

        return view('configuracion.funciones', compact('CurrentPage', 'funciones'));
    }

    public function estado(Request $request, FuncionAvanzada $funcion): JsonResponse
    {
        $datos = $request->validate([
            'activa' => ['required', 'boolean'],
            'confirmado' => ['sometimes', 'boolean'],
        ]);

        if ($datos['activa'] && ! $funcion->disponible) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esta función todavía no está disponible en el CRM.',
            ], 422);
        }

        // FR-005a: desactivar Mercado Libre con una cuenta conectada exige confirmación
        // explícita. La desactivación nunca borra credenciales ni desconecta la cuenta.
        if ($funcion->clave === 'mercadolibre' && ! $datos['activa'] && $funcion->activa) {
            $hayCuentaConectada = MercadoLibreCuenta::query()
                ->where('estado', EstadoConexion::Conectada->value)
                ->exists();

            if ($hayCuentaConectada && ! ($datos['confirmado'] ?? false)) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => 'Al desactivar Mercado Libre se suspenden las operaciones contra la API, pero la vinculación de la cuenta se conserva. ¿Confirmás la desactivación?',
                    'requiere_confirmacion' => true,
                ], 409);
            }
        }

        // FR-006a (spec 015): mismo patrón que la rama 'mercadolibre' de arriba —
        // desactivar con una conexión activa exige confirmación explícita, y nunca
        // borra las credenciales ni desconecta la tienda.
        if ($funcion->clave === 'tiendanube' && ! $datos['activa'] && $funcion->activa) {
            $conexionActiva = TiendanubeConexionRest::actual()->estado === EstadoConexionTiendanube::Conectada;

            if ($conexionActiva && ! ($datos['confirmado'] ?? false)) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => 'Al desactivar Tiendanube se suspenden las operaciones contra la API, pero la configuración se conserva. ¿Confirmás la desactivación?',
                    'requiere_confirmacion' => true,
                ], 409);
            }
        }

        $funcion->activa = $datos['activa'];
        $funcion->actualizada_por = $request->user()->id;
        $funcion->actualizada_en = now();
        $funcion->save();

        return response()->json([
            'ok' => true,
            'mensaje' => $funcion->activa ? 'Función activada.' : 'Función desactivada.',
            'funcion' => ['clave' => $funcion->clave, 'activa' => $funcion->activa],
        ]);
    }
}
