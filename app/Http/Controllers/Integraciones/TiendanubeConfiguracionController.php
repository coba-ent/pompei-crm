<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\GuardarConfiguracionVentasTiendanubeRequest;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\ListaPrecio;
use App\Models\Vendedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Configuración & Ajustes → Tiendanube: modo sólo lectura, configuración de
 * ventas e historial de la conexión Application REST (spec 022/024). El
 * flujo de conexión en sí (botón "Conectar") lo maneja
 * TiendanubeConexionRestController — acá no queda ningún formulario de
 * credenciales manuales ni una acción "Probar conexión" separada.
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
        $depositoEfectivo = TiendanubeConexionRest::actual()->depositoEfectivoONulo();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();

        return view('configuracion.tiendanube.index', compact(
            'CurrentPage', 'depositos', 'categoriasVenta', 'cuentasTesoreria', 'depositoPorDefecto', 'depositoEfectivo', 'listasPrecio', 'vendedores'
        ));
    }

    /** Configuración de ventas de Tiendanube (spec 017, contracts §3, FR-010/FR-016/FR-045/FR-047/FR-050). */
    public function guardarVentas(GuardarConfiguracionVentasTiendanubeRequest $request): JsonResponse
    {
        $conexion = TiendanubeConexionRest::actual();
        $datos = $request->validated();
        $listaPrecioIdAnterior = $conexion->lista_precio_id;

        $conexion->update($datos);

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
            'configuracion' => $conexion->fresh(),
        ]);
    }

    public function modoSoloLectura(Request $request): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $conexion = TiendanubeConexionRest::actual();
        $conexion->modo_solo_lectura = $datos['activo'];
        $conexion->actualizada_por = $request->user()->id;
        $conexion->save();

        return response()->json([
            'ok' => true,
            'mensaje' => $datos['activo']
                ? 'Modo sólo lectura activado. Las escrituras hacia Tiendanube quedan bloqueadas.'
                : 'Modo sólo lectura desactivado.',
            'modo_solo_lectura' => $conexion->modo_solo_lectura,
        ]);
    }

    public function historial(Request $request)
    {
        $query = TiendanubeRestOperacionLog::query()->orderByDesc('created_at');

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
            ->editColumn('created_at', fn (TiendanubeRestOperacionLog $op) => $op->created_at?->local()->format('Y-m-d H:i:s'))
            ->toJson();
    }
}
