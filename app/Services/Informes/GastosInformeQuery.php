<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Informe de Gastos (spec 067, US2): detalle plano + subtotales por Categoría → Subcategoría.
 *
 * La pantalla dibuja el árbol Categoría → Subcategoría colapsado, con los subtotales que
 * devuelve {@see self::subtotales()}, y recién al desplegar una subcategoría pide sus filas a
 * {@see self::filasDeGrupo()}. Los subtotales se calculan siempre sobre **todo el conjunto
 * filtrado** (FR-023), nunca sobre lo que hay dibujado en pantalla. El detalle plano completo
 * de {@see self::detalle()} sigue alimentando el export y el PDF.
 */
class GastosInformeQuery
{
    public const SIN_CATEGORIA = 'Sin categoría';

    public const SIN_SUBCATEGORIA = 'Sin subcategoría';

    /**
     * Query de detalle plano, ya filtrada (export, PDF y base de las demás consultas).
     *
     * Un gasto cuya categoría es una **raíz** se agrupa bajo esa categoría con subcategoría
     * "Sin subcategoría"; uno sin categoría cae en "Sin categoría"/"Sin subcategoría". Ninguno
     * de los dos casos se omite del informe (edge case de la spec) — de ahí que los joins sean
     * LEFT y que los rótulos se resuelvan en SQL con COALESCE, y no filtrando en PHP.
     */
    public function detalle(Request $request): Builder
    {
        $query = DB::table('gastos')
            ->leftJoin('categorias as cat', 'cat.id', '=', 'gastos.categoria_id')
            ->leftJoin('categorias as padre', 'padre.id', '=', 'cat.categoria_padre_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'gastos.cuenta_tesoreria_id')
            ->whereNull('gastos.deleted_at')
            ->selectRaw(
                'gastos.id as id, gastos.fecha as fecha, '.
                $this->sqlCategoria().' as categoria, '.
                $this->sqlSubcategoria().' as subcategoria, '.
                'gastos.descripcion as descripcion, '.
                'cuentas_tesoreria.nombre as medio_pago, '.
                'gastos.monto as total, '.
                'gastos.pendiente as pendiente'
            );

        $this->aplicarFiltros($query, $request);

        return $query;
    }

    /**
     * Nombre del grupo de nivel 1. Si la categoría del gasto tiene padre, el grupo es el padre;
     * si es raíz, es ella misma; si no hay categoría, el rótulo explícito.
     */
    private function sqlCategoria(): string
    {
        return "COALESCE(padre.nombre, cat.nombre, '".self::SIN_CATEGORIA."')";
    }

    /** Nivel 2: sólo hay subcategoría real cuando la categoría del gasto tiene padre. */
    private function sqlSubcategoria(): string
    {
        return "CASE WHEN padre.id IS NOT NULL THEN cat.nombre ELSE '".self::SIN_SUBCATEGORIA."' END";
    }

    /** Los 5 filtros del contrato. AND entre campos, OR dentro de cada multi-valor. */
    public function aplicarFiltros(Builder $query, Request $request): void
    {
        $rango = $this->rango($request);
        $query->whereDate('gastos.fecha', '>=', $rango['desde'])
            ->whereDate('gastos.fecha', '<=', $rango['hasta']);

        if ($request->filled('categoria_id')) {
            // El filtro de Categoría es por la **raíz**: elegir "Oficina" tiene que traer también
            // los gastos imputados a "Oficina → Luz", que es como se los ve agrupados en pantalla.
            $ids = (array) $request->input('categoria_id');
            $query->where(fn (Builder $q) => $q->whereIn('cat.id', $ids)->orWhereIn('padre.id', $ids));
        }

        if ($request->filled('subcategoria_id')) {
            $query->whereIn('cat.id', (array) $request->input('subcategoria_id'));
        }

        if ($request->filled('cuenta_tesoreria_id')) {
            $query->whereIn('gastos.cuenta_tesoreria_id', (array) $request->input('cuenta_tesoreria_id'));
        }

        if ($request->filled('usuario_id')) {
            $query->whereIn('gastos.usuario_id', (array) $request->input('usuario_id'));
        }

        if ($request->filled('estado_pago')) {
            $query->where('gastos.pendiente', $request->input('estado_pago') === 'pendiente');
        }
    }

    /**
     * Filas de detalle de un grupo Categoría → Subcategoría concreto.
     *
     * Es lo que se carga al desplegar una subcategoría en pantalla. Se filtra sobre la subquery
     * de {@see self::detalle()} y no por ids, porque los grupos son **rótulos** resueltos en SQL
     * (COALESCE / CASE): "Sin categoría" y "Sin subcategoría" no tienen id que consultar.
     */
    public function filasDeGrupo(Request $request, string $categoria, string $subcategoria): Collection
    {
        return DB::query()
            ->fromSub($this->detalle($request), 'g')
            ->where('g.categoria', $categoria)
            ->where('g.subcategoria', $subcategoria)
            ->orderBy('g.fecha')
            ->orderBy('g.id')
            ->get();
    }

    /** Rango de emisión efectivo. Por defecto, el mes actual (FR-004b). */
    public function rango(Request $request): array
    {
        return [
            'desde' => $request->filled('fecha_desde') ? $request->input('fecha_desde') : now()->startOfMonth()->toDateString(),
            'hasta' => $request->filled('fecha_hasta') ? $request->input('fecha_hasta') : now()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Total del período y árbol de subtotales Categoría → Subcategoría.
     *
     * Se agrupa en SQL sobre el conjunto filtrado completo. El total general se calcula como la
     * suma de los subtotales que se devuelven (y no con un SUM aparte) para que la invariante
     * `Gasto Total = Σ subtotales` (FR-026) sea cierta por construcción y no por coincidencia.
     *
     * @return array{fecha_desde: string, fecha_hasta: string, gasto_total: float, grupos: list<array{categoria: string, subtotal: float, subcategorias: list<array{subcategoria: string, subtotal: float}>}>}
     */
    public function subtotales(Request $request): array
    {
        $rango = $this->rango($request);

        $filas = DB::query()
            ->fromSub($this->detalle($request), 'g')
            ->selectRaw('g.categoria as categoria, g.subcategoria as subcategoria, COALESCE(SUM(g.total), 0) as subtotal')
            ->groupBy('g.categoria', 'g.subcategoria')
            ->orderBy('g.categoria')
            ->orderBy('g.subcategoria')
            ->get();

        $grupos = [];

        foreach ($filas as $fila) {
            $categoria = $fila->categoria;
            $subtotal = round((float) $fila->subtotal, 2);

            $grupos[$categoria] ??= ['categoria' => $categoria, 'subtotal' => 0.0, 'subcategorias' => []];
            $grupos[$categoria]['subtotal'] = round($grupos[$categoria]['subtotal'] + $subtotal, 2);
            $grupos[$categoria]['subcategorias'][] = [
                'subcategoria' => $fila->subcategoria,
                'subtotal' => $subtotal,
            ];
        }

        $grupos = array_values($grupos);

        return [
            'fecha_desde' => $rango['desde'],
            'fecha_hasta' => $rango['hasta'],
            'gasto_total' => round(array_sum(array_column($grupos, 'subtotal')), 2),
            'grupos' => $grupos,
        ];
    }
}
