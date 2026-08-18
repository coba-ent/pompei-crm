<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Gasto;
use App\Models\MovimientoTesoreria;
use App\Models\OtroIngreso;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Tesoreria\CuentaCorriente;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla de aterrizaje `/dashboard` (spec 010): agrega, en modo sólo lectura,
 * datos ya existentes de Ventas/Otros Ingresos (spec 008), Compras/Gastos
 * (spec 009) y Tesorería (spec 007). Tesorería y Cuentas a Cobrar/Pagar se
 * resuelven una sola vez en `index()` (no dependen del selector de período,
 * FR-008); KPIs/totales/gráfico mensual/donas/rankings tienen su propio
 * endpoint AJAX para recalcularse al cambiar de período sin recargar la página.
 */
class DashboardController extends Controller
{
    private const PERIODOS_VALIDOS = ['hoy', 'semana', 'mes_actual', 'mes_anterior', 'anio_actual'];

    private const LIMITE_MOVIMIENTOS_RECIENTES = 8;

    private const TOP_N_RANKING = 10;

    public function __construct(
        private readonly Tesoreria $tesoreria,
        private readonly CuentaCorriente $cuentaCorriente,
    ) {
    }

    public function index(Request $request)
    {
        $CurrentPage = 'home';

        $permisos = $this->permisosRubros($request->user());
        $resultadoVisible = $this->resultadoVisible($permisos);

        $saldos = null;
        $movimientosRecientes = collect();
        $cuentasACobrar = null;
        $cuentasAPagar = null;

        if ($permisos['tesoreria']) {
            $saldos = $this->tesoreria->saldos();

            $movimientosRecientes = MovimientoTesoreria::query()
                ->with('cuenta')
                ->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->limit(self::LIMITE_MOVIMIENTOS_RECIENTES)
                ->get()
                ->map(fn (MovimientoTesoreria $m) => [
                    'fecha' => $m->fecha->toDateString(),
                    'cuenta' => optional($m->cuenta)->nombre,
                    'monto' => (float) $m->monto,
                ])
                ->values();

            $cuentasACobrar = $this->cuentaCorriente->aging('cliente');
            $cuentasAPagar = $this->cuentaCorriente->aging('proveedor');
        }

        return view('dashboard.index', compact(
            'CurrentPage', 'permisos', 'resultadoVisible', 'saldos', 'movimientosRecientes', 'cuentasACobrar', 'cuentasAPagar'
        ));
    }

    /**
     * Qué rubros/widgets del Dashboard puede ver el usuario logueado (spec 070): un array de
     * booleans indexado por rubro, calculado a partir de los permisos `.ver` ya existentes.
     * Admin ve todo (`tienePermiso()` ya lo resuelve vía `esAdmin()`).
     *
     * @return array{ventas: bool, otros_ingresos: bool, compras: bool, gastos: bool, clientes: bool, productos: bool, tesoreria: bool}
     */
    private function permisosRubros(\App\Models\User $user): array
    {
        return [
            'ventas' => $user->tienePermiso('ventas.ver'),
            'otros_ingresos' => $user->tienePermiso('otros-ingresos.ver'),
            'compras' => $user->tienePermiso('compras.ver'),
            'gastos' => $user->tienePermiso('gastos.ver'),
            'clientes' => $user->tienePermiso('clientes.ver'),
            'productos' => $user->tienePermiso('productos.ver'),
            'tesoreria' => $user->tienePermiso('tesoreria.ver'),
        ];
    }

    /**
     * El KPI "Resultado" combina los 4 rubros (ventas + otros ingresos - compras - gastos): sólo
     * se muestra si el usuario tiene permiso sobre los 4, porque un "resultado" parcial sería un
     * número engañoso (spec 070, FR-003).
     */
    private function resultadoVisible(array $permisos): bool
    {
        return $permisos['ventas'] && $permisos['otros_ingresos'] && $permisos['compras'] && $permisos['gastos'];
    }

    /** 4 KPIs con variación % vs. el período anterior equivalente (US1), filtrados por permiso (spec 070). */
    public function kpis(Request $request): JsonResponse
    {
        $permisos = $this->permisosRubros($request->user());

        [$desde, $hasta, $desdeAnterior, $hastaAnterior] = $this->rangoPeriodo($request->input('periodo'));

        $actual = $this->metricasRango($desde, $hasta, $permisos);
        $anterior = $this->metricasRango($desdeAnterior, $hastaAnterior, $permisos);

        $variacion = fn (float $valorActual, float $valorAnterior): ?float => $valorAnterior == 0.0
            ? null
            : round((($valorActual - $valorAnterior) / $valorAnterior) * 100, 2);

        $respuesta = [];

        if ($permisos['ventas']) {
            $respuesta['ventas_creadas'] = [
                'valor' => $actual['ventas_creadas'],
                'variacion_pct' => $variacion($actual['ventas_creadas'], $anterior['ventas_creadas']),
            ];
            $respuesta['venta_promedio'] = [
                'valor' => $actual['venta_promedio'],
                'variacion_pct' => $variacion($actual['venta_promedio'], $anterior['venta_promedio']),
            ];
            $respuesta['cantidad_ventas'] = [
                'valor' => $actual['cantidad_ventas'],
                'variacion_pct' => $variacion($actual['cantidad_ventas'], $anterior['cantidad_ventas']),
            ];
        }

        if ($this->resultadoVisible($permisos)) {
            $respuesta['resultado'] = [
                'valor' => $actual['resultado'],
                'variacion_pct' => $variacion($actual['resultado'], $anterior['resultado']),
            ];
        }

        return response()->json($respuesta);
    }

    /** Totales de Ventas/Otros Ingresos/Compras/Gastos del rango actual, para las barras de progreso (US1), filtrados por permiso (spec 070). */
    public function totales(Request $request): JsonResponse
    {
        $permisos = $this->permisosRubros($request->user());

        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));
        $metricas = $this->metricasRango($desde, $hasta, $permisos);

        $respuesta = [];

        if ($permisos['ventas']) {
            $respuesta['ventas'] = $metricas['ventas_creadas'];
        }
        if ($permisos['otros_ingresos']) {
            $respuesta['otros_ingresos'] = $metricas['otros_ingresos'];
        }
        if ($permisos['compras']) {
            $respuesta['compras'] = $metricas['compras'];
        }
        if ($permisos['gastos']) {
            $respuesta['gastos'] = $metricas['gastos'];
        }

        return response()->json($respuesta);
    }

    /** Serie de 12 meses fija (Ventas/Otros Ingresos/Compras/Gastos), no depende del período (US2, FR-008), filtrada por permiso (spec 070). */
    public function graficoMensual(Request $request): JsonResponse
    {
        $permisos = $this->permisosRubros($request->user());

        $labels = [];
        $series = [];
        foreach (['ventas', 'otros_ingresos', 'compras', 'gastos'] as $rubro) {
            if ($permisos[$rubro]) {
                $series[$rubro] = [];
            }
        }

        $inicioPrimerMes = Carbon::now()->local()->startOfDay()->startOfMonth()->subMonths(11);

        for ($i = 0; $i < 12; $i++) {
            $desde = $inicioPrimerMes->copy()->addMonths($i);
            $hasta = $desde->copy()->endOfMonth();

            $labels[] = $desde->format('Y-m');
            if ($permisos['ventas']) {
                $series['ventas'][] = $this->montoNetoQuery(Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta);
            }
            if ($permisos['otros_ingresos']) {
                $series['otros_ingresos'][] = (float) OtroIngreso::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('monto');
            }
            if ($permisos['compras']) {
                $series['compras'][] = $this->montoNetoQuery(Compra::class, 'compra_id', 'fecha_emision', $desde, $hasta);
            }
            if ($permisos['gastos']) {
                $series['gastos'][] = (float) Gasto::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('monto');
            }
        }

        return response()->json(['labels' => $labels, 'series' => $series]);
    }

    /** 3 donas de composición por categoría (Ventas/Compras/Gastos), dentro del período filtrado (US5), filtradas por permiso (spec 070). */
    public function donas(Request $request): JsonResponse
    {
        $permisos = $this->permisosRubros($request->user());

        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));

        $respuesta = [];

        if ($permisos['ventas']) {
            $respuesta['ventas'] = $this->composicionPorCategoria(Venta::class, 'fecha_emision', 'total', $desde, $hasta);
        }
        if ($permisos['compras']) {
            $respuesta['compras'] = $this->composicionPorCategoria(Compra::class, 'fecha_emision', 'total', $desde, $hasta);
        }
        if ($permisos['gastos']) {
            $respuesta['gastos'] = $this->composicionPorCategoria(Gasto::class, 'fecha', 'monto', $desde, $hasta);
        }

        return response()->json($respuesta);
    }

    /** Ranking de Clientes (por monto vendido) y de Productos (por cantidad vendida), dentro del período (US6), filtrados por permiso (spec 070). */
    public function rankings(Request $request): JsonResponse
    {
        $permisos = $this->permisosRubros($request->user());

        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));

        $respuesta = [];

        if ($permisos['ventas'] && $permisos['clientes']) {
            $porCliente = Venta::whereBetween('fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
                ->selectRaw('cliente_id, SUM(total) as monto')
                ->groupBy('cliente_id')
                ->orderByDesc('monto')
                ->limit(self::TOP_N_RANKING)
                ->get();

            $nombresClientes = Cliente::whereIn('id', $porCliente->pluck('cliente_id'))->pluck('nombre', 'id');

            $respuesta['clientes'] = $porCliente->map(fn ($fila) => [
                'nombre' => $nombresClientes[$fila->cliente_id] ?? 'Sin cliente',
                'monto' => round((float) $fila->monto, 2),
            ])->values();
        }

        if ($permisos['ventas'] && $permisos['productos']) {
            $porProducto = VentaItem::query()
                ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
                ->whereNull('ventas.deleted_at')
                ->whereBetween('ventas.fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
                ->selectRaw('venta_items.producto_id as producto_id, SUM(venta_items.cantidad) as cantidad')
                ->groupBy('venta_items.producto_id')
                ->orderByDesc('cantidad')
                ->limit(self::TOP_N_RANKING)
                ->get();

            $nombresProductos = Producto::whereIn('id', $porProducto->pluck('producto_id'))->pluck('nombre', 'id');

            $respuesta['productos'] = $porProducto->map(fn ($fila) => [
                'nombre' => $nombresProductos[$fila->producto_id] ?? 'Sin producto',
                'cantidad' => round((float) $fila->cantidad, 3),
            ])->values();
        }

        return response()->json($respuesta);
    }

    /** @return array{ventas_creadas: float, venta_promedio: float, cantidad_ventas: int, resultado: float, otros_ingresos: float, compras: float, gastos: float} */
    private function metricasRango(Carbon $desde, Carbon $hasta, ?array $permisos = null): array
    {
        // Sin filtro explícito (llamadas internas/tests legacy) se calcula todo, como antes.
        $permisos ??= ['ventas' => true, 'otros_ingresos' => true, 'compras' => true, 'gastos' => true];

        // Límite superior con hora de fin de día: las columnas `date` de MySQL no tienen
        // componente horario, pero comparar por string (como hace SQLite en tests) requiere
        // que el límite superior no sea "menor" que un valor con hora — necesario para que un
        // rango de un solo día (periodo "hoy", FR-010) incluya sus propias operaciones.
        $rango = [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()];

        $ventasCreadas = 0.0;
        $cantidadVentas = 0;
        $ventaPromedio = 0.0;
        if ($permisos['ventas']) {
            $ventasCreadas = $this->montoNetoQuery(Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta);
            $cantidadVentas = (int) Venta::whereBetween('fecha_emision', $rango)->count();
            $ventaPromedio = $cantidadVentas > 0 ? round($ventasCreadas / $cantidadVentas, 2) : 0.0;
        }

        $otrosIngresos = $permisos['otros_ingresos']
            ? (float) OtroIngreso::whereBetween('fecha', $rango)->where('pendiente', false)->sum('monto')
            : 0.0;
        $compras = $permisos['compras']
            ? $this->montoNetoQuery(Compra::class, 'compra_id', 'fecha_emision', $desde, $hasta)
            : 0.0;
        $gastos = $permisos['gastos']
            ? (float) Gasto::whereBetween('fecha', $rango)->where('pendiente', false)->sum('monto')
            : 0.0;

        // Sólo tiene sentido si los 4 rubros se calcularon con permiso real (ver resultadoVisible());
        // si a alguno le faltó permiso, este valor no se expone en la respuesta.
        $resultado = round($ventasCreadas + $otrosIngresos - $compras - $gastos, 2);

        return [
            'ventas_creadas' => round($ventasCreadas, 2),
            'venta_promedio' => $ventaPromedio,
            'cantidad_ventas' => $cantidadVentas,
            'resultado' => $resultado,
            'otros_ingresos' => round($otrosIngresos, 2),
            'compras' => round($compras, 2),
            'gastos' => round($gastos, 2),
        ];
    }

    /** @return array{0: string, 1: float}[] lista [nombre_categoria, monto] ordenada desc */
    private function composicionPorCategoria(string $modelo, string $campoFecha, string $campoMonto, Carbon $desde, Carbon $hasta): array
    {
        $columnaFk = match ($modelo) {
            Venta::class => 'venta_id',
            Compra::class => 'compra_id',
            default => null,
        };

        if ($columnaFk !== null) {
            // Venta/Compra: neta de NC/ND por categoría (FR-006), ver montoNetoPorCategoriaQuery().
            $porCategoria = $this->montoNetoPorCategoriaQuery($modelo, $columnaFk, $campoFecha, $desde, $hasta);
            $idsCategorias = collect(array_keys($porCategoria))->filter(fn ($id) => $id !== 'sin_categoria')->values();
            $categorias = Categoria::whereIn('id', $idsCategorias)->get()->keyBy('id');

            $totales = [];
            foreach ($porCategoria as $categoriaId => $monto) {
                $categoria = $categoriaId !== 'sin_categoria' ? $categorias->get($categoriaId) : null;
                $nombre = ($categoria && $categoria->activo) ? $categoria->nombre : 'Sin categoría';
                $totales[$nombre] = ($totales[$nombre] ?? 0.0) + $monto;
            }
        } else {
            // Gasto: sin NC/ND, se mantiene la suma bruta agrupada por categoría.
            $filas = $modelo::whereBetween($campoFecha, [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()])
                ->selectRaw("categoria_id, SUM({$campoMonto}) as monto")
                ->groupBy('categoria_id')
                ->get();

            $idsCategorias = $filas->pluck('categoria_id')->filter()->values();
            $categorias = Categoria::whereIn('id', $idsCategorias)->get()->keyBy('id');

            $totales = [];
            foreach ($filas as $fila) {
                $categoria = $fila->categoria_id ? $categorias->get($fila->categoria_id) : null;
                $nombre = ($categoria && $categoria->activo) ? $categoria->nombre : 'Sin categoría';
                $totales[$nombre] = ($totales[$nombre] ?? 0.0) + (float) $fila->monto;
            }
        }

        arsort($totales);

        return collect($totales)
            ->map(fn ($monto, $nombre) => ['categoria' => $nombre, 'monto' => round($monto, 2)])
            ->values()
            ->all();
    }

    /**
     * Monto neto de Venta/Compra en `[$desde, $hasta]`, restando NC y sumando ND por período de
     * emisión de cada nota (research.md Decisión 1): componente 1 = suma de las ventas/compras del
     * propio rango; componente 2 = ajuste de las notas cuyo período cae en el rango pero cuya
     * venta/compra base cayó en otro período.
     *
     * ## Sin piso de $0 (18/08/2026)
     *
     * FR-007 pedía recortar en $0 el neto de cada Venta/Compra individual cuando sus notas de
     * crédito superaban el total original. **Contagram no lo hace**, verificado por dos caminos:
     *
     * - En agosto de 2026 el Dashboard mostraba $32.463.153,90 de compras contra sus
     *   $32.444.829,98. La culpable era una sola: la compra 2424 de Pompei SRL, total $54.504,80
     *   con una NC de $72.828,74, que quedaba en $0 en vez de restar sus −$18.323,94.
     * - Es el **único** caso de neto negativo en toda la historia, así que si Contagram aplicara
     *   el piso su total histórico de compras tendría que ser exactamente $18.323,94 más alto que
     *   el nuestro. No lo es: da 16 centavos.
     *
     * El requisito se cambió a pedido del usuario, con el criterio de que las dos aplicaciones
     * tienen que dar lo mismo. Una devolución mayor que la compra que la origina **resta** del
     * período, que es lo que efectivamente pasó con la plata.
     */
    private function montoNetoQuery(string $modelo, string $columnaFk, string $campoFecha, Carbon $desde, Carbon $hasta): float
    {
        $tabla = (new $modelo)->getTable();
        // Límite superior con hora de fin de día (mismo motivo que en metricasRango()): necesario
        // para que un rango de un solo día (periodo "hoy") incluya sus propias operaciones.
        $rango = [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()];

        $componente1 = DB::selectOne(
            "SELECT COALESCE(SUM(x.monto_bruto), 0) as monto
            FROM (
                SELECT (t.total
                    + COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.{$columnaFk} = t.id AND n.tipo = 'debito' AND n.deleted_at IS NULL AND n.fecha_emision BETWEEN ? AND ?), 0)
                    - COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.{$columnaFk} = t.id AND n.tipo = 'credito' AND n.deleted_at IS NULL AND n.fecha_emision BETWEEN ? AND ?), 0)
                ) as monto_bruto
                FROM {$tabla} t
                WHERE t.deleted_at IS NULL AND t.{$campoFecha} BETWEEN ? AND ?
            ) x",
            [...$rango, ...$rango, ...$rango]
        )->monto;

        $componente2 = DB::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN n.tipo = 'debito' THEN n.monto ELSE -n.monto END), 0) as monto
            FROM notas_credito_debito n
            JOIN {$tabla} t ON t.id = n.{$columnaFk}
            WHERE n.fecha_emision BETWEEN ? AND ?
              AND n.deleted_at IS NULL
              AND t.deleted_at IS NULL
              AND t.{$campoFecha} NOT BETWEEN ? AND ?",
            [...$rango, ...$rango]
        )->monto;

        return (float) $componente1 + (float) $componente2;
    }

    /**
     * Igual que {@see montoNetoQuery()} pero agrupado por `categoria_id` (categoría vigente
     * heredada de la Venta/Compra, FR-006), para alimentar `composicionPorCategoria()`.
     *
     * @return array<int|string, float> monto neto indexado por categoria_id (clave string 'sin_categoria' si null)
     */
    private function montoNetoPorCategoriaQuery(string $modelo, string $columnaFk, string $campoFecha, Carbon $desde, Carbon $hasta): array
    {
        $tabla = (new $modelo)->getTable();
        // Límite superior con hora de fin de día (mismo motivo que en metricasRango()): necesario
        // para que un rango de un solo día (periodo "hoy") incluya sus propias operaciones.
        $rango = [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()];

        $filas1 = DB::select(
            "SELECT x.categoria_id as categoria_id, COALESCE(SUM(x.monto_bruto), 0) as monto
            FROM (
                SELECT t.categoria_id, (t.total
                    + COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.{$columnaFk} = t.id AND n.tipo = 'debito' AND n.deleted_at IS NULL AND n.fecha_emision BETWEEN ? AND ?), 0)
                    - COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.{$columnaFk} = t.id AND n.tipo = 'credito' AND n.deleted_at IS NULL AND n.fecha_emision BETWEEN ? AND ?), 0)
                ) as monto_bruto
                FROM {$tabla} t
                WHERE t.deleted_at IS NULL AND t.{$campoFecha} BETWEEN ? AND ?
            ) x
            GROUP BY x.categoria_id",
            [...$rango, ...$rango, ...$rango]
        );

        $filas2 = DB::select(
            "SELECT t.categoria_id as categoria_id, COALESCE(SUM(CASE WHEN n.tipo = 'debito' THEN n.monto ELSE -n.monto END), 0) as monto
            FROM notas_credito_debito n
            JOIN {$tabla} t ON t.id = n.{$columnaFk}
            WHERE n.fecha_emision BETWEEN ? AND ?
              AND n.deleted_at IS NULL
              AND t.deleted_at IS NULL
              AND t.{$campoFecha} NOT BETWEEN ? AND ?
            GROUP BY t.categoria_id",
            [...$rango, ...$rango]
        );

        $totales = [];
        foreach ([...$filas1, ...$filas2] as $fila) {
            $clave = $fila->categoria_id ?? 'sin_categoria';
            $totales[$clave] = ($totales[$clave] ?? 0.0) + (float) $fila->monto;
        }

        return $totales;
    }

    /**
     * Rango de fechas del período elegido + su rango anterior equivalente (mismo largo, inmediatamente previo).
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} [desde, hasta, desde_anterior, hasta_anterior]
     */
    private function rangoPeriodo(?string $periodo): array
    {
        if (! in_array($periodo, self::PERIODOS_VALIDOS, true)) {
            $periodo = 'mes_actual';
        }

        $hoy = Carbon::now()->local()->startOfDay();

        switch ($periodo) {
            case 'hoy':
                $desde = $hoy->copy();
                $hasta = $hoy->copy();
                break;
            case 'semana':
                $desde = $hoy->copy()->startOfWeek();
                $hasta = $hoy->copy()->endOfWeek();
                break;
            case 'mes_anterior':
                $desde = $hoy->copy()->subMonthNoOverflow()->startOfMonth();
                $hasta = $hoy->copy()->subMonthNoOverflow()->endOfMonth();
                break;
            case 'anio_actual':
                $desde = $hoy->copy()->startOfYear();
                $hasta = $hoy->copy()->endOfYear();
                break;
            default: // mes_actual
                $desde = $hoy->copy()->startOfMonth();
                $hasta = $hoy->copy()->endOfMonth();
                break;
        }

        $dias = $desde->diffInDays($hasta) + 1;
        $hastaAnterior = $desde->copy()->subDay();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1);

        return [$desde, $hasta, $desdeAnterior, $hastaAnterior];
    }
}
