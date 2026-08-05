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

        return view('dashboard.index', compact(
            'CurrentPage', 'saldos', 'movimientosRecientes', 'cuentasACobrar', 'cuentasAPagar'
        ));
    }

    /** 4 KPIs con variación % vs. el período anterior equivalente (US1). */
    public function kpis(Request $request): JsonResponse
    {
        [$desde, $hasta, $desdeAnterior, $hastaAnterior] = $this->rangoPeriodo($request->input('periodo'));

        $actual = $this->metricasRango($desde, $hasta);
        $anterior = $this->metricasRango($desdeAnterior, $hastaAnterior);

        $variacion = fn (float $valorActual, float $valorAnterior): ?float => $valorAnterior == 0.0
            ? null
            : round((($valorActual - $valorAnterior) / $valorAnterior) * 100, 2);

        return response()->json([
            'ventas_creadas' => [
                'valor' => $actual['ventas_creadas'],
                'variacion_pct' => $variacion($actual['ventas_creadas'], $anterior['ventas_creadas']),
            ],
            'venta_promedio' => [
                'valor' => $actual['venta_promedio'],
                'variacion_pct' => $variacion($actual['venta_promedio'], $anterior['venta_promedio']),
            ],
            'cantidad_ventas' => [
                'valor' => $actual['cantidad_ventas'],
                'variacion_pct' => $variacion($actual['cantidad_ventas'], $anterior['cantidad_ventas']),
            ],
            'resultado' => [
                'valor' => $actual['resultado'],
                'variacion_pct' => $variacion($actual['resultado'], $anterior['resultado']),
            ],
        ]);
    }

    /** Totales de Ventas/Otros Ingresos/Compras/Gastos del rango actual, para las barras de progreso (US1). */
    public function totales(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));
        $metricas = $this->metricasRango($desde, $hasta);

        return response()->json([
            'ventas' => $metricas['ventas_creadas'],
            'otros_ingresos' => $metricas['otros_ingresos'],
            'compras' => $metricas['compras'],
            'gastos' => $metricas['gastos'],
        ]);
    }

    /** Serie de 12 meses fija (Ventas/Otros Ingresos/Compras/Gastos), no depende del período (US2, FR-008). */
    public function graficoMensual(): JsonResponse
    {
        $labels = [];
        $series = ['ventas' => [], 'otros_ingresos' => [], 'compras' => [], 'gastos' => []];

        $inicioPrimerMes = Carbon::today()->startOfMonth()->subMonths(11);

        for ($i = 0; $i < 12; $i++) {
            $desde = $inicioPrimerMes->copy()->addMonths($i);
            $hasta = $desde->copy()->endOfMonth();

            $labels[] = $desde->format('Y-m');
            $series['ventas'][] = $this->montoNetoQuery(Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta);
            $series['otros_ingresos'][] = (float) OtroIngreso::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('monto');
            $series['compras'][] = $this->montoNetoQuery(Compra::class, 'compra_id', 'fecha_emision', $desde, $hasta);
            $series['gastos'][] = (float) Gasto::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('monto');
        }

        return response()->json(['labels' => $labels, 'series' => $series]);
    }

    /** 3 donas de composición por categoría (Ventas/Compras/Gastos), dentro del período filtrado (US5). */
    public function donas(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));

        return response()->json([
            'ventas' => $this->composicionPorCategoria(Venta::class, 'fecha_emision', 'total', $desde, $hasta),
            'compras' => $this->composicionPorCategoria(Compra::class, 'fecha_emision', 'total', $desde, $hasta),
            'gastos' => $this->composicionPorCategoria(Gasto::class, 'fecha', 'monto', $desde, $hasta),
        ]);
    }

    /** Ranking de Clientes (por monto vendido) y de Productos (por cantidad vendida), dentro del período (US6). */
    public function rankings(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoPeriodo($request->input('periodo'));

        $porCliente = Venta::whereBetween('fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('cliente_id, SUM(total) as monto')
            ->groupBy('cliente_id')
            ->orderByDesc('monto')
            ->limit(self::TOP_N_RANKING)
            ->get();

        $nombresClientes = Cliente::whereIn('id', $porCliente->pluck('cliente_id'))->pluck('nombre', 'id');

        $rankingClientes = $porCliente->map(fn ($fila) => [
            'nombre' => $nombresClientes[$fila->cliente_id] ?? 'Sin cliente',
            'monto' => round((float) $fila->monto, 2),
        ])->values();

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

        $rankingProductos = $porProducto->map(fn ($fila) => [
            'nombre' => $nombresProductos[$fila->producto_id] ?? 'Sin producto',
            'cantidad' => round((float) $fila->cantidad, 3),
        ])->values();

        return response()->json([
            'clientes' => $rankingClientes,
            'productos' => $rankingProductos,
        ]);
    }

    /** @return array{ventas_creadas: float, venta_promedio: float, cantidad_ventas: int, resultado: float, otros_ingresos: float, compras: float, gastos: float} */
    private function metricasRango(Carbon $desde, Carbon $hasta): array
    {
        // Límite superior con hora de fin de día: las columnas `date` de MySQL no tienen
        // componente horario, pero comparar por string (como hace SQLite en tests) requiere
        // que el límite superior no sea "menor" que un valor con hora — necesario para que un
        // rango de un solo día (periodo "hoy", FR-010) incluya sus propias operaciones.
        $rango = [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()];

        $ventasCreadas = $this->montoNetoQuery(Venta::class, 'venta_id', 'fecha_emision', $desde, $hasta);
        $cantidadVentas = (int) Venta::whereBetween('fecha_emision', $rango)->count();
        $ventaPromedio = $cantidadVentas > 0 ? round($ventasCreadas / $cantidadVentas, 2) : 0.0;

        $otrosIngresos = (float) OtroIngreso::whereBetween('fecha', $rango)->where('pendiente', false)->sum('monto');
        $compras = $this->montoNetoQuery(Compra::class, 'compra_id', 'fecha_emision', $desde, $hasta);
        $gastos = (float) Gasto::whereBetween('fecha', $rango)->where('pendiente', false)->sum('monto');

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
     * emisión de cada nota (research.md Decisión 1): componente 1 = suma por fila con piso $0 de
     * las ventas/compras del propio rango; componente 2 = ajuste crudo sin piso de notas cuyo
     * período cae en el rango pero cuya venta/compra base cayó en otro período. El piso $0 se
     * aplica con `CASE WHEN ... > 0` sobre una subconsulta derivada (en vez de `GREATEST`/`MAX`
     * multi-argumento) para que la misma consulta corra tanto en MySQL (producción) como en
     * SQLite (tests).
     */
    private function montoNetoQuery(string $modelo, string $columnaFk, string $campoFecha, Carbon $desde, Carbon $hasta): float
    {
        $tabla = (new $modelo)->getTable();
        // Límite superior con hora de fin de día (mismo motivo que en metricasRango()): necesario
        // para que un rango de un solo día (periodo "hoy") incluya sus propias operaciones.
        $rango = [$desde->toDateString(), $hasta->copy()->endOfDay()->toDateTimeString()];

        $componente1 = DB::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN x.monto_bruto > 0 THEN x.monto_bruto ELSE 0 END), 0) as monto
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
            "SELECT x.categoria_id as categoria_id, COALESCE(SUM(CASE WHEN x.monto_bruto > 0 THEN x.monto_bruto ELSE 0 END), 0) as monto
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

        $hoy = Carbon::today();

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
