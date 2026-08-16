<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reporte Final (spec 068, US3): el resultado del período en dos lecturas.
 *
 * - **`devengado`** — "Ventas Vs. Compras": lo facturado, se haya cobrado/pagado o no. Se imputa
 *   por la fecha del comprobante. Los gastos **incluyen los pendientes**.
 * - **`caja`** — "Cobros Vs Pagos": el dinero que efectivamente entró y salió. Se imputa por la
 *   fecha **del cobro o del pago**, no la del comprobante que lo origina — es justo lo que
 *   distingue una base de la otra (FR-037b) — y abre un nivel más por Cuenta de Tesorería. Los
 *   gastos pendientes **no** entran: no implican salida real de dinero.
 *
 * Son dos queries independientes a propósito (research R5): una sola parametrizada por un flag
 * sería más corta y mucho más difícil de auditar, y es el tipo de código donde un filtro se
 * arrastra de una base a la otra sin que nadie lo note.
 *
 * ## Signos
 *
 * Devuelve **todo en positivo**, con un campo `naturaleza` (`ingreso`/`egreso`) por bloque. La
 * pantalla y el PDF muestran los egresos en positivo y `Resultado = Ingresos − Egresos` en las
 * dos vistas (FR-035). El doble estándar de signos de Contagram (R2) es de formato de archivo, no
 * de cálculo, y vive exclusivamente en {@see \App\Exports\Informes\ReporteFinalExport}.
 */
class ReporteFinalQuery
{
    public const SIN_CATEGORIA = 'Sin categoría';

    public const SIN_SUBCATEGORIA = 'Sin subcategoría';

    public const SIN_CUENTA = 'Sin cuenta de tesorería';

    /** Vistas válidas; cualquier otro valor cae en la primera. */
    public const VISTAS = ['devengado', 'caja'];

    /**
     * Árbol completo de la vista pedida, más los totales.
     *
     * @return array{desde: string, hasta: string, vista: string, totales: array<string, float>, bloques: list<array<string, mixed>>}
     */
    public function arbol(Request $request): array
    {
        $rango = $this->rango($request);
        $vista = $this->vista($request);

        $bloques = $vista === 'caja'
            ? $this->bloquesCaja($rango)
            : $this->bloquesDevengado($rango);

        $excluidas = $this->excluidas($request);

        return [
            'desde' => $rango['desde'],
            'hasta' => $rango['hasta'],
            'vista' => $vista,
            'totales' => $this->totales($bloques, $excluidas),
            'bloques' => $bloques,
        ];
    }

    public function vista(Request $request): string
    {
        $vista = (string) $request->input('vista', 'devengado');

        return in_array($vista, self::VISTAS, true) ? $vista : 'devengado';
    }

    /**
     * Categorías destildadas en el simulador, tal como las manda el front.
     *
     * Se identifican por la **clave** del nodo (`bloque|id`) y no por el id de la categoría: el
     * nodo "Sin categoría" no tiene id, y sin una clave propia sería inexcluible. Es una
     * corrección al contrato de endpoints, que sólo contemplaba ids.
     *
     * @return list<string>
     */
    public function excluidas(Request $request): array
    {
        return array_map('strval', (array) $request->input('excluidas', []));
    }

    /**
     * Rango efectivo. Por defecto el mes calendario completo en curso (FR-003).
     *
     * @return array{desde: string, hasta: string}
     */
    public function rango(Request $request): array
    {
        $desde = $request->filled('desde') ? $request->input('desde') : $request->input('fecha_desde');
        $hasta = $request->filled('hasta') ? $request->input('hasta') : $request->input('fecha_hasta');

        return [
            'desde' => $desde ?: now()->startOfMonth()->toDateString(),
            'hasta' => $hasta ?: now()->endOfMonth()->toDateString(),
        ];
    }

    // -----------------------------------------------------------------------------------
    // Vista devengado — Ventas Vs. Compras
    // -----------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function bloquesDevengado(array $rango): array
    {
        return [
            $this->bloque('ventas', 'Ventas', 'ingreso', $this->ventasDevengado($rango)),
            $this->bloque('otros_ingresos', 'Otros Ingresos', 'ingreso', $this->sueltosDevengado('otros_ingresos', $rango)),
            $this->bloque('compras', 'Compras', 'egreso', $this->comprasDevengado($rango)),
            $this->bloque('gastos', 'Gastos', 'egreso', $this->gastosDevengado($rango)),
        ];
    }

    /**
     * Ventas del período por categoría, **incluidas sus NC y ND**.
     *
     * Las notas se agrupan por la categoría de la venta que ajustan y entran con su signo (NC
     * resta, ND suma), de modo que la categoría muestre lo realmente facturado y no el bruto.
     * Una nota sin venta asociada cae en "Sin categoría", que acá es un caso real:
     * `ventas.categoria_id` es nullable.
     */
    private function ventasDevengado(array $rango): array
    {
        $ventas = $this->porCategoria(
            DB::table('ventas')
                ->whereNull('ventas.deleted_at')
                ->whereBetween('ventas.fecha_emision', [$rango['desde'], $rango['hasta']]),
            'ventas.categoria_id',
            'ventas.total',
        );

        $notas = $this->porCategoria(
            DB::table('notas_credito_debito')
                ->leftJoin('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
                ->whereNull('notas_credito_debito.deleted_at')
                ->whereNull('notas_credito_debito.compra_id')
                ->where(fn (Builder $q) => $q->whereNull('notas_credito_debito.venta_id')->orWhereNull('ventas.deleted_at'))
                ->whereBetween('notas_credito_debito.fecha_emision', [$rango['desde'], $rango['hasta']]),
            'ventas.categoria_id',
            "(CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END) * notas_credito_debito.monto",
        );

        return $this->fusionar($ventas, $notas);
    }

    /** Compras del período por categoría, incluidas sus NC y ND, con el mismo criterio. */
    private function comprasDevengado(array $rango): array
    {
        $compras = $this->porCategoria(
            DB::table('compras')
                ->whereNull('compras.deleted_at')
                ->whereBetween('compras.fecha_emision', [$rango['desde'], $rango['hasta']]),
            'compras.categoria_id',
            'compras.total',
        );

        $notas = $this->porCategoria(
            DB::table('notas_credito_debito')
                ->leftJoin('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
                ->whereNull('notas_credito_debito.deleted_at')
                ->whereNotNull('notas_credito_debito.compra_id')
                ->whereNull('compras.deleted_at')
                ->whereBetween('notas_credito_debito.fecha_emision', [$rango['desde'], $rango['hasta']]),
            'compras.categoria_id',
            "(CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END) * notas_credito_debito.monto",
        );

        return $this->fusionar($compras, $notas);
    }

    /** Otros Ingresos del período por categoría. Devengado: entran también los pendientes. */
    private function sueltosDevengado(string $tabla, array $rango): array
    {
        return $this->porCategoria(
            DB::table($tabla)
                ->whereNull($tabla.'.deleted_at')
                ->whereBetween($tabla.'.fecha', [$rango['desde'], $rango['hasta']]),
            $tabla.'.categoria_id',
            $tabla.'.monto',
        );
    }

    /** Gastos del período: Categoría → Subcategoría, **con** los pendientes (FR-031). */
    private function gastosDevengado(array $rango): array
    {
        return $this->porCategoriaYSub($this->baseGastos($rango));
    }

    // -----------------------------------------------------------------------------------
    // Vista caja — Cobros Vs Pagos
    // -----------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function bloquesCaja(array $rango): array
    {
        return [
            $this->bloque('ventas', 'Ventas Cobradas', 'ingreso', $this->cobrosCaja($rango)),
            $this->bloque('otros_ingresos', 'Otros Ingresos', 'ingreso', $this->sueltosCaja('otros_ingresos', $rango)),
            $this->bloque('compras', 'Compras Pagadas', 'egreso', $this->pagosCaja($rango)),
            $this->bloque('gastos', 'Gastos', 'egreso', $this->gastosCaja($rango)),
        ];
    }

    /**
     * Cobros del período: imputados por `cobros.fecha` y agrupados por la categoría de la venta
     * que los origina (FR-037b), abiertos por cuenta de tesorería.
     */
    private function cobrosCaja(array $rango): array
    {
        return $this->porCategoriaYCuenta(
            DB::table('cobros')
                ->join('ventas', 'ventas.id', '=', 'cobros.venta_id')
                ->whereNull('cobros.deleted_at')
                ->whereNull('ventas.deleted_at')
                ->whereBetween('cobros.fecha', [$rango['desde'], $rango['hasta']]),
            'ventas.categoria_id',
            'cobros.cuenta_tesoreria_id',
            'cobros.monto',
        );
    }

    /** Ídem del lado egresos: pagos por la categoría de la compra que los origina. */
    private function pagosCaja(array $rango): array
    {
        return $this->porCategoriaYCuenta(
            DB::table('pagos')
                ->join('compras', 'compras.id', '=', 'pagos.compra_id')
                ->whereNull('pagos.deleted_at')
                ->whereNull('compras.deleted_at')
                ->whereBetween('pagos.fecha', [$rango['desde'], $rango['hasta']]),
            'compras.categoria_id',
            'pagos.cuenta_tesoreria_id',
            'pagos.monto',
        );
    }

    /** Otros Ingresos efectivamente cobrados: los pendientes no son dinero que entró. */
    private function sueltosCaja(string $tabla, array $rango): array
    {
        return $this->porCategoriaYCuenta(
            DB::table($tabla)
                ->whereNull($tabla.'.deleted_at')
                ->where($tabla.'.pendiente', false)
                ->whereBetween($tabla.'.fecha', [$rango['desde'], $rango['hasta']]),
            $tabla.'.categoria_id',
            $tabla.'.cuenta_tesoreria_id',
            $tabla.'.monto',
        );
    }

    /** Gastos pagados: Categoría → Subcategoría → Cuenta, **sin** los pendientes (FR-031). */
    private function gastosCaja(array $rango): array
    {
        return $this->porCategoriaYSub($this->baseGastos($rango)->where('gastos.pendiente', false), conCuenta: true);
    }

    private function baseGastos(array $rango): Builder
    {
        return DB::table('gastos')
            ->whereNull('gastos.deleted_at')
            ->whereBetween('gastos.fecha', [$rango['desde'], $rango['hasta']]);
    }

    // -----------------------------------------------------------------------------------
    // Agregaciones
    // -----------------------------------------------------------------------------------

    /**
     * `categoria → monto`, con la categoría resuelta por su **raíz** (una venta imputada a una
     * categoría hija se agrupa bajo su padre, que es como se la ve en pantalla).
     *
     * @return list<array{id: int|null, etiqueta: string, monto: float, hijos: list<mixed>}>
     */
    private function porCategoria(Builder $query, string $columnaCategoria, string $expresionMonto): array
    {
        $filas = $this->conCategoria($query, $columnaCategoria)
            ->groupBy('c_padre.id', 'c_padre.nombre', 'c_hoja.id', 'c_hoja.nombre')
            ->selectRaw(self::SELECT_CATEGORIA.', SUM('.$expresionMonto.') as monto')
            ->get();

        return $filas->map(fn ($f) => [
            'id' => $f->cat_raiz_id === null ? null : (int) $f->cat_raiz_id,
            'etiqueta' => $f->cat_raiz_nombre ?? self::SIN_CATEGORIA,
            'monto' => round((float) $f->monto, 2),
            'hijos' => [],
        ])->all();
    }

    /** `categoria → cuenta de tesorería → monto` (tercer nivel de la vista caja, FR-033). */
    private function porCategoriaYCuenta(Builder $query, string $columnaCategoria, string $columnaCuenta, string $expresionMonto): array
    {
        $filas = $this->conCategoria($query, $columnaCategoria)
            ->leftJoin('cuentas_tesoreria as ct', 'ct.id', '=', $columnaCuenta)
            ->groupBy('c_padre.id', 'c_padre.nombre', 'c_hoja.id', 'c_hoja.nombre', 'ct.id', 'ct.nombre')
            ->selectRaw(self::SELECT_CATEGORIA.', ct.nombre as cuenta, SUM('.$expresionMonto.') as monto')
            ->get();

        $categorias = [];

        foreach ($filas as $f) {
            $clave = $f->cat_raiz_id === null ? 'sin' : (string) $f->cat_raiz_id;

            $categorias[$clave] ??= [
                'id' => $f->cat_raiz_id === null ? null : (int) $f->cat_raiz_id,
                'etiqueta' => $f->cat_raiz_nombre ?? self::SIN_CATEGORIA,
                'monto' => 0.0,
                'hijos' => [],
                'cuentas' => [],
            ];

            $monto = round((float) $f->monto, 2);
            $categorias[$clave]['monto'] += $monto;
            $categorias[$clave]['cuentas'][$f->cuenta ?? self::SIN_CUENTA] = $monto;
        }

        return array_values(array_map(function (array $categoria) {
            $categoria['hijos'] = $this->conCuentasVisibles($categoria['cuentas']);
            unset($categoria['cuentas']);
            $categoria['monto'] = round($categoria['monto'], 2);

            return $categoria;
        }, $categorias));
    }

    /**
     * `categoría → subcategoría [→ cuenta] → monto`, la forma propia del bloque Gastos.
     *
     * `gastos.categoria_id` puede apuntar a una categoría hija: en ese caso la categoría es el
     * padre y la subcategoría la hija; si no tiene padre, la subcategoría es el rótulo de
     * fallback. Mismo criterio que el Informe de Gastos de la Tanda 1.
     */
    private function porCategoriaYSub(Builder $query, bool $conCuenta = false): array
    {
        $query = $query
            ->leftJoin('categorias as cat', 'cat.id', '=', 'gastos.categoria_id')
            ->leftJoin('categorias as padre', 'padre.id', '=', 'cat.categoria_padre_id')
            ->groupBy('cat.id', 'cat.nombre', 'padre.id', 'padre.nombre');

        if ($conCuenta) {
            $query = $query
                ->leftJoin('cuentas_tesoreria as ct', 'ct.id', '=', 'gastos.cuenta_tesoreria_id')
                ->groupBy('ct.id', 'ct.nombre')
                ->addSelect(DB::raw('ct.nombre as cuenta'));
        }

        $filas = $query->selectRaw(
            'COALESCE(padre.id, cat.id) as cat_raiz_id, '.
            "COALESCE(padre.nombre, cat.nombre, '".self::SIN_CATEGORIA."') as cat_raiz_nombre, ".
            "CASE WHEN padre.id IS NOT NULL THEN cat.nombre ELSE '".self::SIN_SUBCATEGORIA."' END as subcategoria, ".
            'SUM(gastos.monto) as monto'
        )->get();

        $categorias = [];

        foreach ($filas as $f) {
            $clave = $f->cat_raiz_id === null ? 'sin' : (string) $f->cat_raiz_id;

            $categorias[$clave] ??= [
                'id' => $f->cat_raiz_id === null ? null : (int) $f->cat_raiz_id,
                'etiqueta' => $f->cat_raiz_nombre,
                'monto' => 0.0,
                'subs' => [],
            ];

            $monto = round((float) $f->monto, 2);
            $categorias[$clave]['monto'] += $monto;

            $sub = &$categorias[$clave]['subs'][$f->subcategoria];
            $sub ??= ['etiqueta' => $f->subcategoria, 'monto' => 0.0, 'cuentas' => []];
            $sub['monto'] += $monto;

            if ($conCuenta) {
                $nombre = $f->cuenta ?? self::SIN_CUENTA;
                $sub['cuentas'][$nombre] = round(($sub['cuentas'][$nombre] ?? 0) + $monto, 2);
            }

            unset($sub);
        }

        return array_values(array_map(function (array $categoria) use ($conCuenta) {
            $categoria['hijos'] = array_values(array_map(fn (array $sub) => [
                'id' => null,
                'etiqueta' => $sub['etiqueta'],
                'monto' => round($sub['monto'], 2),
                'hijos' => $conCuenta ? $this->conCuentasVisibles($sub['cuentas']) : [],
            ], $categoria['subs']));

            unset($categoria['subs']);
            $categoria['monto'] = round($categoria['monto'], 2);

            return $categoria;
        }, $categorias));
    }

    /** Proyección de la categoría raíz, compartida por las dos agregaciones que la usan. */
    private const SELECT_CATEGORIA =
        'COALESCE(c_padre.id, c_hoja.id) as cat_raiz_id, '.
        'COALESCE(c_padre.nombre, c_hoja.nombre) as cat_raiz_nombre';

    /**
     * Engancha la categoría de la fila y su padre.
     *
     * Se agrupa por la **raíz**: una venta imputada a una categoría hija suma bajo su padre, que
     * es como se la ve en pantalla. `categoria_id` es nullable en `ventas` y en `compras`, así que
     * la fila sin categoría es un caso real y cae en el rótulo de fallback.
     */
    private function conCategoria(Builder $query, string $columnaCategoria): Builder
    {
        return $query
            ->leftJoin('categorias as c_hoja', 'c_hoja.id', '=', $columnaCategoria)
            ->leftJoin('categorias as c_padre', 'c_padre.id', '=', 'c_hoja.categoria_padre_id');
    }

    /**
     * Cuentas de tesorería de una categoría con actividad.
     *
     * Contagram lista **todas** las cuentas visibles aunque el monto sea $0,00 (FR-038); las no
     * visibles aparecen sólo si tuvieron movimiento en el período, para que ningún importe quede
     * escondido detrás de una cuenta oculta.
     *
     * @param  array<string, float>  $montos  nombre de cuenta → monto acumulado
     * @return list<array<string, mixed>>
     */
    private function conCuentasVisibles(array $montos): array
    {
        $visibles = DB::table('cuentas_tesoreria')
            ->where('visible', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();

        $nombres = $visibles;

        foreach (array_keys($montos) as $nombre) {
            if (! in_array($nombre, $nombres, true)) {
                $nombres[] = $nombre;
            }
        }

        return array_map(fn (string $nombre) => [
            'id' => null,
            'etiqueta' => $nombre,
            'monto' => round((float) ($montos[$nombre] ?? 0), 2),
            'hijos' => [],
        ], $nombres);
    }

    // -----------------------------------------------------------------------------------
    // Armado y totales
    // -----------------------------------------------------------------------------------

    /**
     * Envuelve las categorías de un bloque, les pone la clave estable del simulador y ordena.
     *
     * `activo` sale siempre en `true`: el estado de la simulación es del cliente, el servidor no
     * lo persiste (data-model §Simulación).
     */
    private function bloque(string $clave, string $etiqueta, string $naturaleza, array $categorias): array
    {
        $categorias = array_values(array_filter(
            $categorias,
            // Una categoría que quedó exactamente en cero (por ejemplo, una venta anulada por su
            // NC en el mismo período) no aporta ninguna lectura y sólo ensucia el árbol.
            fn (array $c) => abs($c['monto']) > 0.004
        ));

        usort($categorias, fn ($a, $b) => strcmp((string) $a['etiqueta'], (string) $b['etiqueta']));

        $categorias = array_map(function (array $categoria) use ($clave) {
            $categoria['clave'] = $clave.'|'.($categoria['id'] ?? 'sin');
            $categoria['activo'] = true;

            return $categoria;
        }, $categorias);

        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'naturaleza' => $naturaleza,
            'total' => round(array_sum(array_column($categorias, 'monto')), 2),
            'categorias' => $categorias,
        ];
    }

    /**
     * Totales de la pantalla, con las categorías excluidas por el simulador ya descontadas.
     *
     * El front hace exactamente esta misma cuenta en el cliente y sin ir al servidor (FR-034);
     * acá se repite para el export y el PDF, que sí la necesitan del lado del servidor.
     *
     * @param  list<array<string, mixed>>  $bloques
     * @param  list<string>  $excluidas
     * @return array{ingresos: float, egresos: float, resultado: float}
     */
    public function totales(array $bloques, array $excluidas = []): array
    {
        $ingresos = 0.0;
        $egresos = 0.0;

        foreach ($bloques as $bloque) {
            foreach ($bloque['categorias'] as $categoria) {
                if (in_array($categoria['clave'], $excluidas, true)) {
                    continue;
                }

                $bloque['naturaleza'] === 'ingreso'
                    ? $ingresos += $categoria['monto']
                    : $egresos += $categoria['monto'];
            }
        }

        $ingresos = round($ingresos, 2);
        $egresos = round($egresos, 2);

        return [
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            // En **pantalla** las dos vistas muestran egresos en positivo y restan (FR-035). El
            // doble estándar de Contagram sólo aparece en el Excel.
            'resultado' => round($ingresos - $egresos, 2),
        ];
    }

    /**
     * Subtotal de un bloque descontando las categorías destildadas.
     *
     * @param  list<string>  $excluidas
     */
    public function totalBloque(array $bloque, array $excluidas = []): float
    {
        $total = 0.0;

        foreach ($bloque['categorias'] as $categoria) {
            if (! in_array($categoria['clave'], $excluidas, true)) {
                $total += $categoria['monto'];
            }
        }

        return round($total, 2);
    }

    /**
     * Suma dos listas de `categoria → monto` (por ejemplo ventas y sus notas) en una sola.
     *
     * @return list<array{id: int|null, etiqueta: string, monto: float, hijos: list<mixed>}>
     */
    private function fusionar(array ...$listas): array
    {
        $acumulado = [];

        foreach ($listas as $lista) {
            foreach ($lista as $item) {
                $clave = $item['id'] === null ? 'sin' : (string) $item['id'];

                $acumulado[$clave] ??= ['id' => $item['id'], 'etiqueta' => $item['etiqueta'], 'monto' => 0.0, 'hijos' => []];
                $acumulado[$clave]['monto'] = round($acumulado[$clave]['monto'] + $item['monto'], 2);
            }
        }

        return array_values($acumulado);
    }
}
