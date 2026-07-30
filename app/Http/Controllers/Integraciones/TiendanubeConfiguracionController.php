<?php

namespace App\Http\Controllers\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\GuardarConfiguracionVentasTiendanubeRequest;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\ListaPrecio;
use App\Models\Vendedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Configuración & Ajustes → Tiendanube (spec 019, corrige spec 015): panel de
 * estado de la conexión OAuth/MCP, modo sólo lectura, desconexión e
 * historial. El flujo de conexión en sí (botón "Conectar con Tiendanube") lo
 * maneja TiendanubeOAuthController — acá no queda ningún formulario de
 * credenciales manuales ni una acción "Probar conexión" separada (la
 * verificación ocurre una sola vez, dentro del callback OAuth, FR-003a).
 */
class TiendanubeConfiguracionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-tiendanube';
        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $categoriasVenta = Categoria::venta()->activas()->orderBy('nombre')->get();
        $cuentasTesoreria = CuentaTesoreria::visibles()->orderBy('nombre')->get();
        $depositoPorDefecto = Deposito::porDefecto();
        $depositoEfectivo = TiendanubeConfiguracion::actual()->depositoEfectivoONulo();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();

        return view('configuracion.tiendanube.index', compact(
            'CurrentPage', 'depositos', 'categoriasVenta', 'cuentasTesoreria', 'depositoPorDefecto', 'depositoEfectivo', 'listasPrecio', 'vendedores'
        ));
    }

    public function estado(): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();

        // FR-006: "no configurada" se deriva de la ausencia de access_token —
        // cubre tanto "nunca conectado" como "desconectado" (data-model.md §1).
        // Presencia, no legibilidad: no dispara el descifrado.
        $noConfigurada = blank($configuracion->getRawOriginal('access_token'));

        $estado = $noConfigurada ? EstadoConexion::NoConfigurada : $configuracion->estado;

        $respuesta = [
            'ok' => true,
            'estado' => $estado->value,
            'configuracion' => $noConfigurada ? null : [
                'conectada_en' => optional($configuracion->conectada_en)->toIso8601String(),
                'scopes_otorgados' => $configuracion->scopes_otorgados,
                'productos_total' => $configuracion->productos_total,
                'modo_solo_lectura' => $configuracion->modo_solo_lectura,
                'token_expira_en' => optional($configuracion->token_expira_en)->toIso8601String(),
                // ceil (no truncar): un token que vence "en 200 días" no debe mostrar 199 sólo porque
                // pasaron unos milisegundos entre que se guardó la fecha y se calculó este número.
                'dias_restantes' => $configuracion->token_expira_en
                    ? max(0, (int) ceil(now()->diffInSeconds($configuracion->token_expira_en, false) / 86400))
                    : null,
                // spec 017: configuración de ventas.
                'creacion_automatica' => $configuracion->creacion_automatica,
                'frecuencia_sync_minutos' => $configuracion->frecuencia_sync_minutos,
                'deposito_id' => $configuracion->deposito_id,
                'categoria_venta_id' => $configuracion->categoria_venta_id,
                'cuenta_tesoreria_id' => $configuracion->cuenta_tesoreria_id,
                'dias_primera_sync' => $configuracion->dias_primera_sync,
                'ultima_sync_en' => optional($configuracion->ultima_sync_en)->toIso8601String(),
                'ultima_sync_resultado' => $configuracion->ultima_sync_resultado,
                'stock_ultima_sync_en' => optional($configuracion->stock_ultima_sync_en)->toIso8601String(),
                'stock_ultima_sync_resultado' => $configuracion->stock_ultima_sync_resultado,
                'lista_precio_id' => $configuracion->lista_precio_id,
                'vendedor_id' => $configuracion->vendedor_id,
            ],
        ];

        if ($estado === EstadoConexion::Caida) {
            $respuesta['ultimo_error'] = $configuracion->ultimo_error;
        }

        return response()->json($respuesta);
    }

    /** Configuración de ventas de Tiendanube (spec 017, contracts §3, FR-010/FR-016/FR-045/FR-047/FR-050). */
    public function guardarVentas(GuardarConfiguracionVentasTiendanubeRequest $request): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();
        $datos = $request->validated();
        $listaPrecioIdAnterior = $configuracion->lista_precio_id;

        $configuracion->update($datos);

        // US9 (spec 018 ampliación, FR-028, contracts §2a): si cambió cuál es la
        // Lista de Precios configurada, empujar de inmediato el precio vigente de
        // la nueva lista a los vínculos que tengan precio ahí — mismo mecanismo
        // que MercadoLibreConfiguracionController::guardarVentas().
        $listaPrecioIdNueva = $datos['lista_precio_id'] ?? null;

        if ($listaPrecioIdNueva !== null && (int) $listaPrecioIdNueva !== (int) $listaPrecioIdAnterior) {
            app(\App\Services\Tiendanube\SincronizadorPrecios::class)->sincronizarListaCompleta((int) $listaPrecioIdNueva);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Configuración de ventas guardada.',
            'configuracion' => $configuracion->fresh(),
        ]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $configuracion = TiendanubeConfiguracion::actual();

        // FR-007: se borra el access_token, se CONSERVA client_id/client_secret
        // (para que una reconexión posterior no dispare un nuevo auto-registro).
        $configuracion->access_token = null;
        $configuracion->scopes_otorgados = null;
        $configuracion->productos_total = null;
        $configuracion->conectada_en = null;
        $configuracion->token_expira_en = null;
        $configuracion->estado = EstadoConexion::NoConfigurada;
        $configuracion->ultimo_error = null;
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

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
