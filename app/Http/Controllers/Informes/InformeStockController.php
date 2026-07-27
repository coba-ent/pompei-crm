<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoProducto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Stock: pantalla propia de sólo lectura sobre `movimientos_stock`
 * (research.md §2) — no crea tabla propia, sólo proyecta y calcula el saldo
 * corrido ("Stock Saldo") vía función de ventana SQL sobre el histórico
 * completo, aplicando los filtros de pantalla como capa externa.
 */
class InformeStockController extends Controller
{
    /** Tipos de operación que el filtro "Operación" expone hoy (FR-013). */
    private const OPERACIONES_DISPONIBLES = ['ajuste', 'transferencia'];

    /** Página del informe (shell con filtros, KPIs y tabla). */
    public function index(Request $request)
    {
        $CurrentPage = 'informe-stock';

        $usuarios = User::orderBy('name')->get(['id', 'name']);
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $tiposProducto = TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $productoId = $request->input('producto_id');
        $productoPreseleccionado = $productoId ? Producto::find($productoId, ['id', 'nombre', 'codigo']) : null;

        $stats = $this->estadisticas($request);

        return view('informes.stock.index', compact(
            'CurrentPage', 'usuarios', 'proveedores', 'tiposProducto', 'productoId', 'productoPreseleccionado', 'stats'
        ));
    }

    /** KPIs (recalculados según los filtros de producto vigentes). */
    public function stats(Request $request): JsonResponse
    {
        return response()->json($this->estadisticas($request));
    }

    /**
     * Query base: `movimientos_stock` + saldo corrido calculado sobre el
     * histórico COMPLETO (sin filtros), en una subconsulta con función de
     * ventana. Los filtros de pantalla se aplican después, como capa externa.
     */
    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        $ventana = DB::table('movimientos_stock')
            ->selectRaw(
                'movimientos_stock.*, SUM(movimientos_stock.cantidad) OVER '.
                '(PARTITION BY movimientos_stock.producto_id, movimientos_stock.variante_id, movimientos_stock.deposito_id '.
                'ORDER BY movimientos_stock.fecha, movimientos_stock.id) as stock_saldo'
            );

        return DB::query()
            ->fromSub($ventana, 'mov')
            ->leftJoin('productos', 'productos.id', '=', 'mov.producto_id')
            ->leftJoin('depositos', 'depositos.id', '=', 'mov.deposito_id')
            ->leftJoin('users', 'users.id', '=', 'mov.usuario_id')
            ->select([
                'mov.id as id',
                'mov.fecha as fecha',
                'mov.tipo as tipo',
                'mov.descripcion as descripcion',
                'mov.cantidad as cantidad',
                'mov.stock_saldo as stock_saldo',
                'mov.usuario_id as usuario_id',
                'productos.id as producto_id',
                'productos.nombre as producto',
                'productos.proveedor_id as proveedor_id',
                'productos.tipo_producto_id as tipo_producto_id',
                'productos.activo as producto_activo',
                'depositos.nombre as deposito',
                'users.name as usuario',
            ]);
    }

    /** Aplica los filtros externos de pantalla (nunca dentro de la ventana). */
    private function aplicarFiltros(\Illuminate\Database\Query\Builder $query, Request $request): void
    {
        if ($request->filled('usuario_id')) {
            $query->where('mov.usuario_id', $request->input('usuario_id'));
        }

        if ($request->filled('operacion') && in_array($request->input('operacion'), self::OPERACIONES_DISPONIBLES, true)) {
            $query->where('mov.tipo', $request->input('operacion'));
        }

        if ($request->filled('proveedor_id')) {
            $query->where('productos.proveedor_id', $request->input('proveedor_id'));
        }

        if ($request->filled('tipo_producto_id')) {
            $query->where('productos.tipo_producto_id', $request->input('tipo_producto_id'));
        }

        if ($request->filled('producto_id')) {
            $query->where('mov.producto_id', $request->input('producto_id'));
        }

        $estado = $request->input('estado', 'todos');
        if ($estado === 'activos') {
            $query->where('productos.activo', true);
        } elseif ($estado === 'inactivos') {
            $query->where('productos.activo', false);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('mov.fecha', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('mov.fecha', '<=', $request->input('fecha_hasta'));
        }
    }

    /** Datos server-side para la DataTable (con la columna calculada `stock_saldo`). */
    public function data(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $this->aplicarFiltros($query, $request);

        return DataTables::of($query)
            ->editColumn('cantidad', fn ($row) => (float) $row->cantidad)
            ->editColumn('stock_saldo', fn ($row) => (float) $row->stock_saldo)
            ->order(function ($query) {
                $query->orderBy('mov.fecha')->orderBy('mov.id');
            })
            ->toJson();
    }

    /**
     * KPIs de valorización (misma fórmula que ProductoController::estadisticas()),
     * acotados a los productos que matchean los filtros de producto vigentes
     * (proveedor, tipo de producto, producto puntual, estado) — no a los
     * filtros propios del histórico de movimientos (usuario/operación/fechas).
     *
     * @return array{unidades_en_stock:float, costo_total:float, valor_venta_total:float}
     */
    private function estadisticas(Request $request): array
    {
        $productos = Producto::query();

        if ($request->filled('proveedor_id')) {
            $productos->where('proveedor_id', $request->input('proveedor_id'));
        }
        if ($request->filled('tipo_producto_id')) {
            $productos->where('tipo_producto_id', $request->input('tipo_producto_id'));
        }
        if ($request->filled('producto_id')) {
            $productos->where('id', $request->input('producto_id'));
        }
        $estado = $request->input('estado', 'todos');
        if ($estado === 'activos') {
            $productos->where('activo', true);
        } elseif ($estado === 'inactivos') {
            $productos->where('activo', false);
        }

        $ids = $productos->pluck('id');

        $valorizacion = DB::table('stocks')
            ->join('productos', 'productos.id', '=', 'stocks.producto_id')
            ->whereIn('stocks.producto_id', $ids)
            ->selectRaw('COALESCE(SUM(stocks.cantidad), 0) as unidades_en_stock')
            ->selectRaw('COALESCE(SUM(stocks.cantidad * productos.costo), 0) as costo_total')
            ->selectRaw('COALESCE(SUM(stocks.cantidad * productos.precio_venta), 0) as valor_venta_total')
            ->first();

        return [
            'unidades_en_stock' => (float) $valorizacion->unidades_en_stock,
            'costo_total' => (float) $valorizacion->costo_total,
            'valor_venta_total' => (float) $valorizacion->valor_venta_total,
        ];
    }
}
