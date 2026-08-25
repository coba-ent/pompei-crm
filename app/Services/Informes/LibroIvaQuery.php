<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Base común del Libro IVA (spec 077): resolución del período, cálculo de los 5 totales del
 * contrato y helpers de filtro compartidos entre IVA Ventas e IVA Compras.
 *
 * ## Sobre la agregación por comprobante (research §D1/§D2, tasks.md T006)
 *
 * `tasks.md` describe la agregación como un `GROUP BY` por comprobante envolviendo en `SUM(...)`
 * las expresiones de {@see DesgloseImpositivoVenta}/{@see DesgloseImpositivoCompra}. Esta
 * implementación logra el mismo resultado (una fila por comprobante con los netos/IVA agregados)
 * con **subqueries correlacionadas** (`SELECT SUM(...) FROM venta_items vi WHERE vi.venta_id =
 * ventas.id`) en vez de un `JOIN + GROUP BY`.
 *
 * Es una decisión deliberada, no un desvío accidental: con `GROUP BY ventas.id` sobre un `JOIN` a
 * `clientes`/`condiciones_iva`, MySQL con `ONLY_FULL_GROUP_BY` (activo en producción — ver memoria
 * del proyecto `mysql-only-full-group-by-tests-sqlite.md`) exige que **todas** las columnas no
 * agregadas sean funcionalmente dependientes de las columnas del `GROUP BY`, y no infiere esa
 * dependencia a través de un `JOIN` a otra tabla aunque la relación sea 1 a 1. Las subqueries
 * evitan el problema de raíz: cada fila sigue siendo "una fila por `ventas.id`" (viene de
 * `FROM ventas`, no de `FROM venta_items`), y la suma por comprobante la hace la subquery
 * correlacionada. Semánticamente es exactamente "SUM(...) agrupado por comprobante" — no
 * reimplementa ninguna clasificación fiscal: sigue envolviendo las mismas expresiones
 * `sqlNeto()`/`sqlIva()` de los servicios de desglose.
 */
abstract class LibroIvaQuery
{
    /** Palabras que clasifican una percepción como IIBB — mismo criterio que los servicios de desglose. */
    protected const PALABRAS_IIBB = ['iibb', 'ingresos brutos', 'ing. brutos', 'ing brutos'];

    /** Detalle completo (comprobantes + NC/ND), ya filtrado por período y filtros — FR-020/026. */
    abstract public function detalle(Request $request): Builder;

    /** FR-007: 422 si falta mes o año, o si el mes no es válido. */
    public function periodoInvalido(Request $request): bool
    {
        if (! $request->filled('mes') || ! $request->filled('anio')) {
            return true;
        }

        $mes = (int) $request->input('mes');

        return $mes < 1 || $mes > 12;
    }

    /**
     * Los 4 totales agregados en SQL sobre el conjunto filtrado completo (FR-012), más
     * Total Facturado calculado en **PHP** como suma de los otros cuatro ya redondeados a 2
     * decimales — research §D6, FR-011, FR-011b. Nunca un quinto `SUM` en SQL: es la garantía de
     * que la ecuación cierra exacta, sin la deriva de centavos que tiene la propia Contagram.
     */
    public function totales(Request $request): array
    {
        $fila = DB::query()->fromSub($this->detalle($request), 'libro_totales')->selectRaw(
            'COALESCE(SUM(libro_totales.neto_no_gravado), 0) as no_gravado, '.
            'COALESCE(SUM(libro_totales.neto_exento), 0) as exento, '.
            'COALESCE(SUM(libro_totales.neto_gravado), 0) as gravado, '.
            'COALESCE(SUM(libro_totales.iva_2_5 + libro_totales.iva_5 + libro_totales.iva_10_5 + libro_totales.iva_21 + libro_totales.iva_27), 0) as iva_total, '.
            'COALESCE(SUM(libro_totales.perc_iva + libro_totales.perc_iibb), 0) as perc_total'
        )->first();

        // FR-011b: redondear por comprobante ya sucedió en cada fila (2 decimales por columna
        // monetaria en SQL); acá se redondea cada uno de los 4 totales agregados, y recién
        // entonces se suman para Total Facturado. Así FR-011 se cumple por construcción.
        $noGravadosExentos = round(round((float) $fila->no_gravado, 2) + round((float) $fila->exento, 2), 2);
        $gravados = round((float) $fila->gravado, 2);
        $ivaTotal = round((float) $fila->iva_total, 2);
        $percTotal = round((float) $fila->perc_total, 2);

        return [
            'no_gravados_exentos' => $noGravadosExentos,
            'gravados' => $gravados,
            'iva_total' => $ivaTotal,
            'perc_iva_iibb_total' => $percTotal,
            'total_facturado' => round($noGravadosExentos + $gravados + $ivaTotal + $percTotal, 2),
        ];
    }

    /**
     * Rango [primer día, último día] del mes/año elegido. Se compara por **período** (año+mes) y
     * no por fecha suelta (research §D3): al calcular los bordes exactos del mes en PHP, comparar
     * con `>=`/`<=` contra esos bordes expresa lo mismo que comparar año+mes, de forma portable
     * entre MySQL y SQLite (sin `EXTRACT`/`strftime`).
     *
     * @return array{0: string, 1: string}
     */
    protected function rangoPeriodo(Request $request): array
    {
        $mes = (int) $request->input('mes');
        $anio = (int) $request->input('anio');
        $inicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();

        return [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()];
    }

    /** Filtro de período sobre una expresión de fecha (columna o `COALESCE(...)`) dentro de un WHERE. */
    protected function filtrarPeriodo(Builder $query, Request $request, string $columnaFechaExpr): void
    {
        [$desde, $hasta] = $this->rangoPeriodo($request);

        $query->whereRaw("({$columnaFechaExpr}) >= ?", [$desde])
            ->whereRaw("({$columnaFechaExpr}) <= ?", [$hasta]);
    }

    /**
     * Filtro EXISTS de Medio de Cobro/Pago — nunca `JOIN` (research §D11): un comprobante con
     * varios cobros/pagos aparecería una vez por cobro y multiplicaría sus importes en los totales.
     */
    protected function filtrarMedioTesoreria(Builder $query, Request $request, string $tablaMovimiento, string $fkComprobante, string $comprobanteIdColumna): void
    {
        if (! $request->filled('cuenta_tesoreria_id')) {
            return;
        }

        $cuentaId = (int) $request->input('cuenta_tesoreria_id');

        $query->whereExists(function (Builder $q) use ($tablaMovimiento, $fkComprobante, $comprobanteIdColumna, $cuentaId) {
            $q->from($tablaMovimiento.' as mov')
                ->whereColumn('mov.'.$fkComprobante, $comprobanteIdColumna)
                ->where('mov.cuenta_tesoreria_id', $cuentaId)
                ->whereNull('mov.deleted_at');
        });
    }

    /** Filtro de Provincia: la fiscal, con respaldo en la comercial (data-model §6). */
    protected function filtrarProvincia(Builder $query, Request $request, string $aliasContraparte): void
    {
        if (! $request->filled('provincia')) {
            return;
        }

        $query->whereRaw(
            "COALESCE({$aliasContraparte}.provincia_fiscal, {$aliasContraparte}.provincia) = ?",
            [$request->input('provincia')]
        );
    }

    /** Total de una columna de `*_conceptos` (percepciones/impuestos internos), SIN prorratear (research §D2, data-model §4). */
    protected function sqlConceptoTotal(string $tabla, string $fk, string $comprobanteIdColumna, string $condicionTipo): string
    {
        return "COALESCE((SELECT SUM(c.monto) FROM {$tabla} c WHERE c.{$fk} = {$comprobanteIdColumna} AND ({$condicionTipo})), 0)";
    }

    protected function sqlPercIva(string $tabla, string $fk, string $comprobanteIdColumna): string
    {
        $condicion = "c.tipo = 'percepcion' AND (".ExpresionSql::contienePalabra('c.concepto', 'iva').
            ') AND NOT ('.$this->sqlMatchPalabras('c.concepto', self::PALABRAS_IIBB).')';

        return $this->sqlConceptoTotal($tabla, $fk, $comprobanteIdColumna, $condicion);
    }

    protected function sqlPercIibb(string $tabla, string $fk, string $comprobanteIdColumna): string
    {
        $condicion = "c.tipo = 'percepcion' AND (".$this->sqlMatchPalabras('c.concepto', self::PALABRAS_IIBB).')';

        return $this->sqlConceptoTotal($tabla, $fk, $comprobanteIdColumna, $condicion);
    }

    protected function sqlImpuestosInternos(string $tabla, string $fk, string $comprobanteIdColumna): string
    {
        return $this->sqlConceptoTotal($tabla, $fk, $comprobanteIdColumna, "c.tipo = 'impuesto_interno'");
    }

    protected function sqlMatchPalabras(string $columna, array $palabras): string
    {
        return collect($palabras)->map(fn (string $p) => ExpresionSql::contienePalabra($columna, $p))->implode(' OR ');
    }
}
