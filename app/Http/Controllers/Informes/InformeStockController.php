<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoProducto;
use App\Models\User;
use App\Models\Venta;
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
    /** Tipos de operación que el filtro "Operación" expone (FR-013, spec 012 quickstart §Escenario 7). */
    private const OPERACIONES_DISPONIBLES = ['entrada', 'salida', 'ajuste', 'transferencia'];

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
            ->leftJoin('ventas', function ($join) {
                $join->on('ventas.id', '=', 'mov.origen_id')
                    ->where('mov.origen_type', '=', Venta::class);
            })
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            // Compras y Notas de Crédito/Débito también mueven stock: sin estos joins la columna
            // "Documento" sólo sabría enlazar las Ventas.
            ->leftJoin('compras', function ($join) {
                $join->on('compras.id', '=', 'mov.origen_id')
                    ->where('mov.origen_type', '=', Compra::class);
            })
            ->leftJoin('notas_credito_debito as notas', function ($join) {
                $join->on('notas.id', '=', 'mov.origen_id')
                    ->where('mov.origen_type', '=', NotaCreditoDebito::class);
            })
            ->select([
                'mov.id as id',
                'mov.fecha as fecha',
                'mov.tipo as tipo',
                'mov.descripcion as descripcion',
                'mov.cantidad as cantidad',
                'mov.stock_saldo as stock_saldo',
                'mov.usuario_id as usuario_id',
                // Qué documento originó el movimiento, para que el informe pueda enlazarlo. Una
                // NC/ND no tiene pantalla propia: cuelga de su Venta o Compra, así que se enlaza
                // el documento padre.
                'mov.origen_type as origen_type',
                'mov.origen_id as origen_id',
                'notas.venta_id as nota_venta_id',
                'notas.compra_id as nota_compra_id',
                'productos.id as producto_id',
                'productos.nombre as producto',
                'productos.codigo as producto_codigo',
                'productos.proveedor_id as proveedor_id',
                'productos.tipo_producto_id as tipo_producto_id',
                'productos.activo as producto_activo',
                'depositos.nombre as deposito',
                'users.name as usuario',
                DB::raw($this->sqlDetalle().' as detalle'),
            ]);
    }

    /**
     * Columna calculada `detalle` (CASE SQL): comprobante + cliente cuando el
     * movimiento viene de una Venta, o `mov.descripcion` para el resto de
     * orígenes (FR-004/FR-005/FR-006). Sintaxis de concatenación portable
     * entre MySQL (producción) y SQLite (tests).
     */
    private function sqlDetalle(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "CASE WHEN ventas.id IS NOT NULL THEN ".
                "ventas.tipo_comprobante || ' ' || ventas.nro_comprobante || ".
                "CASE WHEN clientes.nombre IS NOT NULL THEN ' - ' || clientes.nombre ELSE '' END ".
                'ELSE mov.descripcion END';
        }

        return "CASE WHEN ventas.id IS NOT NULL THEN ".
            "CONCAT(ventas.tipo_comprobante, ' ', ventas.nro_comprobante, ".
            "IF(clientes.nombre IS NOT NULL, CONCAT(' - ', clientes.nombre), '')) ".
            'ELSE mov.descripcion END';
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
            // El link se resuelve acá y no en el JS: las rutas viven en el servidor, y así el
            // front sólo pinta lo que le llega en vez de repetir el mapeo origen -> ruta.
            ->addColumn('documento', fn ($row) => $this->documentoOrigen($row))
            // El orden lo decide la pantalla, pero SIEMPRE termina desempatando por fecha+id: dos
            // movimientos del mismo instante tienen que salir en el orden en que ocurrieron, o el
            // "Stock Saldo" (saldo corrido) parece contradecirse entre filas contiguas.
            //
            // Por defecto es DESCENDENTE: es un histórico de movimientos y lo primero que se
            // quiere ver es lo último que pasó, no el movimiento más viejo de la base (28/08/2026).
            ->order(function ($query) use ($request) {
                $direccion = $this->direccionPedida($request);

                $query->orderBy('mov.fecha', $direccion)->orderBy('mov.id', $direccion);
            })
            ->toJson();
    }

    /**
     * Dirección de ordenamiento pedida por la DataTable, acotada a la columna Fecha.
     *
     * La tabla ordena por fecha y no por columna arbitraria: el resto de las columnas o son
     * calculadas (documento) o no aportan como criterio, y ordenar por ellas rompería la lectura
     * del saldo corrido.
     */
    private function direccionPedida(Request $request): string
    {
        $direccion = strtolower((string) $request->input('order.0.dir', 'desc'));

        return $direccion === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Documento que originó el movimiento, con su enlace, para la columna "ID" del informe.
     *
     * Un movimiento puede no tener origen (ajuste manual, sincronización con Mercado Libre o
     * Tiendanube): en ese caso la celda queda vacía, igual que en Contagram.
     *
     * Las Notas de Crédito/Débito no tienen pantalla propia —cuelgan de su Venta o Compra—, así
     * que se enlaza el documento padre. Cuando la nota quedó sin padre (las migradas: `venta_id`
     * es nullable desde la migración del 18/08/2026) se muestra el id sin enlace, porque no hay
     * adónde ir.
     *
     * @return array{id:int, url:?string, tipo:string}|null
     */
    private function documentoOrigen(object $fila): ?array
    {
        return match ($fila->origen_type) {
            Venta::class => [
                'id' => (int) $fila->origen_id,
                'url' => route('ventas.show', $fila->origen_id),
                'tipo' => 'Venta',
            ],
            Compra::class => [
                'id' => (int) $fila->origen_id,
                'url' => route('compras.show', $fila->origen_id),
                'tipo' => 'Compra',
            ],
            NotaCreditoDebito::class => [
                'id' => (int) $fila->origen_id,
                'url' => match (true) {
                    (bool) $fila->nota_venta_id => route('ventas.show', $fila->nota_venta_id),
                    (bool) $fila->nota_compra_id => route('compras.show', $fila->nota_compra_id),
                    default => null,
                },
                'tipo' => 'Nota de Crédito/Débito',
            ],
            default => null,
        };
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
