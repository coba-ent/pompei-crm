<?php

namespace App\Http\Controllers\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\GuardarCredencialesTiendanubeRequest;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Services\Tiendanube\ClienteTiendanube;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Configuración & Ajustes → Tiendanube (spec 015): credenciales de la
 * Aplicación personalizada, panel de estado, modo sólo lectura, prueba de
 * conexión, desconexión e historial. Sin flujo OAuth (research.md §R1).
 */
class TiendanubeConfiguracionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-tiendanube';

        return view('configuracion.tiendanube.index', compact('CurrentPage'));
    }

    public function estado(): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();

        // "No configurada" es "nunca se cargó ni siquiera un store_id", no "está
        // incompleta ahora mismo": si usara estaCompleta() (que también exige
        // access_token), Desconectar (que sólo borra el token, FR-010) colapsaría
        // siempre a "no_configurada" en vez de "Desconectada" con los datos de
        // tienda conservados (FR-011). estaCompleta() sigue siendo el gate de
        // FR-004 para "Probar conexión", pero no el de qué mostrar acá.
        $noConfigurada = blank($configuracion->store_id);

        $estado = $noConfigurada ? EstadoConexion::NoConfigurada : $configuracion->estado;
        $respuesta = [
            'ok' => true,
            'estado' => $estado->value,
            'configuracion' => $noConfigurada ? null : [
                'store_id' => $configuracion->store_id,
                // Presencia, no legibilidad (mismo criterio que estaCompleta()): no
                // dispara el descifrado, por lo que nunca revienta esta pantalla.
                'token_cargado' => filled($configuracion->getRawOriginal('access_token')),
                'modo_solo_lectura' => $configuracion->modo_solo_lectura,
                'credenciales_guardadas_en' => optional($configuracion->credenciales_guardadas_en)->toIso8601String(),
            ],
            'tienda' => (! $noConfigurada && ($configuracion->nombre_tienda || $configuracion->dominio)) ? [
                'nombre' => $configuracion->nombre_tienda,
                'dominio' => $configuracion->dominio,
                'pais' => $configuracion->pais,
                'moneda' => $configuracion->moneda,
                'ultima_verificacion_en' => optional($configuracion->ultima_verificacion_en)->toIso8601String(),
            ] : null,
        ];

        if ($estado === EstadoConexion::Caida) {
            $respuesta['ultimo_error'] = $configuracion->ultimo_error;
        }

        return response()->json($respuesta);
    }

    public function credenciales(GuardarCredencialesTiendanubeRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $configuracion = TiendanubeConfiguracion::actual();

        // Si el token guardado quedó ilegible (edge case spec.md), no se puede comparar
        // contra el nuevo: se asume distinto, total lo estamos reemplazando igual.
        try {
            $tokenAnterior = $configuracion->access_token;
        } catch (DecryptException $e) {
            $tokenAnterior = null;
        }

        $cambioToken = filled($datos['access_token'] ?? null) && $datos['access_token'] !== $tokenAnterior;

        if (filled($datos['store_id'] ?? null)) {
            $configuracion->store_id = $datos['store_id'];
        }
        if (filled($datos['access_token'] ?? null)) {
            $configuracion->access_token = $datos['access_token'];
        }

        $configuracion->credenciales_guardadas_en = now();
        $configuracion->actualizada_por = $request->user()->id;

        $advertencia = null;

        // FR-005: reemplazar el token con una conexión activa invalida esa conexión
        // hasta que se vuelva a probar — no se mantiene "conectada" a ciegas.
        if ($cambioToken && $configuracion->estado === EstadoConexion::Conectada) {
            $configuracion->estado = EstadoConexion::Desconectada;
            $advertencia = 'La conexión anterior queda invalidada hasta que la vuelvas a probar.';
        }

        $configuracion->save();

        $respuesta = [
            'ok' => true,
            'mensaje' => 'Credenciales guardadas. Probá la conexión para verificarlas.',
        ];

        if ($advertencia) {
            $respuesta['advertencia'] = $advertencia;
        }

        return response()->json($respuesta);
    }

    public function probar(ClienteTiendanube $cliente): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();

        if (! $configuracion->estaCompleta()) {
            $faltante = blank($configuracion->store_id) ? 'el identificador de tienda' : 'el token de acceso';

            return response()->json([
                'ok' => false,
                'mensaje' => "Faltan datos: cargá {$faltante} antes de probar la conexión.",
            ], 409);
        }

        // La prueba de conexión debe poder ejecutarse aunque la función "tiendanube"
        // esté desactivada — es como el usuario verifica que sus credenciales sirven
        // antes (o después) de decidir si la deja activa (mismo criterio que T051a de ML).
        $respuesta = $cliente->probarConexion(['omitir_guard_funcion' => true]);

        if ($respuesta->fallo()) {
            return response()->json([
                'ok' => false,
                'mensaje' => $respuesta->mensajeError ?? 'No se pudo probar la conexión.',
                'estado' => $configuracion->fresh()->estado->value,
            ]);
        }

        $tienda = $configuracion->fresh();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Conexión verificada con éxito.',
            'estado' => EstadoConexion::Conectada->value,
            'tienda' => [
                'nombre' => $tienda->nombre_tienda,
                'dominio' => $tienda->dominio,
                'pais' => $tienda->pais,
                'moneda' => $tienda->moneda,
            ],
        ]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();

        $configuracion->access_token = null;
        $configuracion->estado = EstadoConexion::Desconectada;
        $configuracion->ultimo_error = null;
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

        // FR-011/spec.md US2#5: a diferencia de Mercado Libre, acá "Desconectar" queda
        // registrado en el historial (es una operación de escritura sobre la conexión).
        TiendanubeOperacionLog::registrar([
            'operacion' => 'desconectar',
            'metodo' => 'LOCAL',
            'endpoint' => '-',
            'sentido' => 'escritura',
            'resultado' => 'exito',
            'usuario_id' => $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tiendanube desconectado.',
        ]);
    }

    public function modoSoloLectura(Request $request): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $configuracion = TiendanubeConfiguracion::actual();
        $configuracion->modo_solo_lectura = $datos['activo'];
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

        return response()->json([
            'ok' => true,
            'mensaje' => $datos['activo']
                ? 'Modo sólo lectura activado. Las escrituras hacia Tiendanube quedan bloqueadas.'
                : 'Modo sólo lectura desactivado.',
            'modo_solo_lectura' => $configuracion->modo_solo_lectura,
        ]);
    }

    public function historial(Request $request)
    {
        $query = TiendanubeOperacionLog::query()->orderByDesc('created_at');

        if ($request->filled('desde')) {
            $query->whereDate('created_at', '>=', $request->get('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('created_at', '<=', $request->get('hasta'));
        }
        if ($request->filled('resultado')) {
            $query->where('resultado', $request->get('resultado'));
        }

        return DataTables::eloquent($query)
            ->editColumn('created_at', fn (TiendanubeOperacionLog $op) => $op->created_at?->format('Y-m-d H:i:s'))
            ->toJson();
    }
}
