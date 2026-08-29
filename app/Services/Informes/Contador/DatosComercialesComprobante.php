<?php

namespace App\Services\Informes\Contador;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Completa cada fila del Libro IVA con los dos datos **comerciales** que Contagram muestra en su Excel
 * y que `LibroIvaVentasQuery`/`LibroIvaComprasQuery` no devuelven: **Provincia** y **Medio de
 * Cobro/Pago** (spec 091, FR-004/FR-005).
 *
 * ## Por qué no se agregan a la query
 *
 * `detalle()` une **tres ramas** con `UNION ALL` —ventas, NC/ND y comprobantes históricos (spec 088)—
 * que deben mantener exactamente las mismas columnas en las mismas posiciones. Agregarle dos columnas
 * obligaría a tocar las tres, incluida la de NC/ND que arma filas SQL literales en PHP; y esa query
 * está verificada peso por peso contra Contagram (specs 077/088) y la consumen además la pantalla y el
 * IVA Digital. Se resuelve acá, con consultas **por lote**, igual que {@see DatosFiscalesComprobante}
 * hace para el IVA Digital — patrón ya probado en este módulo.
 */
class DatosComercialesComprobante
{
    /** Contagram muestra un guion cuando el cliente no tiene provincia cargada. */
    private const SIN_PROVINCIA = '-';

    /**
     * @param  Collection<int, object>  $filas  filas de `detalle()` ya materializadas
     * @return Collection<string, array{provincia: string, medio: string}> keyed por {@see clave()}
     */
    public function resolver(Collection $filas, bool $esCompras = false): Collection
    {
        $idsComprobante = $filas->filter(fn ($f) => $this->grupo($f) === 'comprobante')->pluck('id')->all();
        $idsNota = $filas->filter(fn ($f) => $this->grupo($f) === 'nota')->pluck('id')->all();

        $provincias = $this->provincias($idsComprobante, $idsNota, $esCompras);
        $medios = $this->medios($idsComprobante, $idsNota, $esCompras);

        $mapa = collect();

        foreach ($filas as $fila) {
            $clave = $this->clave($fila);
            $mapa->put($clave, [
                'provincia' => $provincias[$clave] ?? self::SIN_PROVINCIA,
                'medio' => $medios[$clave] ?? '',
            ]);
        }

        return $mapa;
    }

    /** Clave del mapa para una fila: distingue comprobante, NC/ND e histórico (spec 088). */
    public function clave(object $fila): string
    {
        return $this->grupo($fila).':'.$fila->id;
    }

    /**
     * Provincia fiscal con respaldo en la comercial — **la misma expresión** que
     * {@see \App\Services\Informes\LibroIvaQuery::filtrarProvincia()}, para que el filtro de la
     * pantalla y esta columna no puedan mostrar cosas distintas.
     *
     * @return array<string, string>
     */
    private function provincias(array $idsComprobante, array $idsNota, bool $esCompras): array
    {
        [$tabla, $fk, $contraparte, $contraparteFk] = $esCompras
            ? ['compras', 'compra_id', 'proveedores', 'proveedor_id']
            : ['ventas', 'venta_id', 'clientes', 'cliente_id'];

        $expresion = "COALESCE(NULLIF({$contraparte}.provincia_fiscal, ''), NULLIF({$contraparte}.provincia, ''))";
        $mapa = [];

        if ($idsComprobante !== []) {
            $filas = DB::table($tabla)
                ->join($contraparte, "{$contraparte}.id", '=', "{$tabla}.{$contraparteFk}")
                ->whereIn("{$tabla}.id", $idsComprobante)
                ->selectRaw("{$tabla}.id as id, {$expresion} as provincia")
                ->get();

            foreach ($filas as $f) {
                $mapa['comprobante:'.$f->id] = $f->provincia ?: self::SIN_PROVINCIA;
            }
        }

        if ($idsNota !== []) {
            $filas = DB::table('notas_credito_debito as n')
                ->join($tabla, "{$tabla}.id", '=', "n.{$fk}")
                ->join($contraparte, "{$contraparte}.id", '=', "{$tabla}.{$contraparteFk}")
                ->whereIn('n.id', $idsNota)
                ->selectRaw("n.id as id, {$expresion} as provincia")
                ->get();

            foreach ($filas as $f) {
                $mapa['nota:'.$f->id] = $f->provincia ?: self::SIN_PROVINCIA;
            }
        }

        return $mapa;
    }

    /**
     * Cuenta de tesorería del **primer** cobro/pago del comprobante. Contagram muestra un solo valor
     * en esa columna y el relevamiento no cubre el caso de varios cobros; se toma el primero por ser
     * determinístico (spec 091, Assumptions).
     *
     * @return array<string, string>
     */
    private function medios(array $idsComprobante, array $idsNota, bool $esCompras): array
    {
        if ($idsComprobante === []) {
            return [];
        }

        [$movimientos, $fk] = $esCompras ? ['pagos', 'compra_id'] : ['cobros', 'venta_id'];

        // Sólo comprobantes: las NC/ND no tienen cobro propio (revierten uno), y los históricos
        // (spec 088) no tienen movimientos de tesorería por diseño.
        return DB::table($movimientos.' as mov')
            ->join('cuentas_tesoreria as ct', 'ct.id', '=', 'mov.cuenta_tesoreria_id')
            ->whereIn("mov.{$fk}", $idsComprobante)
            ->whereNull('mov.deleted_at')
            ->orderBy('mov.id')
            ->get(["mov.{$fk} as comprobante_id", 'ct.nombre as medio'])
            ->reverse() // `keyBy` conserva el último de cada clave: al invertir, queda el primero.
            ->keyBy(fn ($f) => 'comprobante:'.$f->comprobante_id)
            ->map(fn ($f) => (string) $f->medio)
            ->all();
    }

    /** 'comprobante' | 'nota' | 'historico' — mismo criterio que {@see DatosFiscalesComprobante}. */
    private function grupo(object $fila): string
    {
        if (($fila->origen ?? null) === 'historico_migracion_agosto_2026') {
            return 'historico';
        }

        $tipo = (string) $fila->tipo;

        return str_starts_with($tipo, 'NC') || str_starts_with($tipo, 'ND') ? 'nota' : 'comprobante';
    }
}
