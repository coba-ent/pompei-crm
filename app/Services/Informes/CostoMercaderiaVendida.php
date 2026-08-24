<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Costo de Mercadería Vendida: costo **congelado** en la línea, con el promedio ponderado de
 * compras como fallback (spec 075; corrige la premisa de la spec 068).
 *
 * ## Cuál es la regla, y por qué cambió
 *
 * La spec 068 afirmaba acá que "la única derivación compatible con lo que muestra Contagram es el
 * promedio ponderado de las compras". **Eso era falso.** Al contrastar el export real contra el
 * informe (`specs/075-cmv-costo-congelado/research.md §R1`) quedó a la vista que Contagram no
 * deriva nada: guarda en cada línea de venta el costo del producto **vigente en el momento de la
 * venta** y lo deja quieto para siempre. El promedio ponderado inflaba el Resultado en ~39%.
 *
 * La regla vigente, entonces, es:
 *
 * ```
 * CMV_linea = COALESCE(venta_items.costo_unitario, costo_promedio_compras, 0) × cantidad_firmada
 * ```
 *
 * 1. **`costo_unitario`** — el costo congelado al crear la línea. Es la regla.
 * 2. **`costo_promedio`** — el promedio ponderado de compras del producto. Es el **fallback**, y
 *    existe sólo por las líneas históricas (importadas de Contagram) que nunca lo congelaron:
 *
 *    ```
 *    costo_promedio(producto) = SUM(precio_unitario × cantidad) / SUM(cantidad)
 *    ```
 *
 *    sobre compras y `compra_items` no eliminados, **sin recorte de fecha** (el promedio es del
 *    producto, no del período consultado).
 * 3. **`0`** — ni costo congelado ni compras.
 *
 * `costo_unitario = 0` **no** es lo mismo que `costo_unitario IS NULL`: el 0 es un costo congelado
 * que vale cero (producto sin costo cargado) y gana sobre el promedio de compras. Por eso el
 * `COALESCE` va sobre la columna cruda y nunca sobre `NULLIF(costo_unitario, 0)`, que reintroduce
 * el bug (invariante I2 de `contracts/cmv-api.md`, con test dedicado).
 *
 * ## Por qué NO es lo mismo que "Costo Actual"
 *
 * "Costo Actual" usa `productos.costo`, el costo **vigente hoy**, y se mueve cada vez que alguien
 * edita la ficha del producto. El CMV mira hacia atrás, a lo que el producto costaba cuando se
 * vendió. Que las dos columnas den distinto sobre el mismo producto no es un error del informe:
 * es su razón de existir (FR-013 / FR-014), y tiene test dedicado.
 *
 * ## Forma de uso
 *
 * El fallback se resuelve con un `LEFT JOIN` a una subconsulta **agrupada una sola vez**, no con
 * una correlacionada por fila: con 5.000 ventas la segunda forma no sostiene SC-002 (research R9).
 */
class CostoMercaderiaVendida
{
    /** Alias con el que la subconsulta entra en el `LEFT JOIN` de los informes. */
    public const ALIAS = 'costo_compras';

    /**
     * Subconsulta `producto_id → costo_promedio`, lista para `leftJoinSub()`.
     *
     * Desde la spec 075 esto ya no es la regla del CMV sino su **fallback**: sólo lo toman las
     * líneas sin costo congelado, es decir las históricas. Su forma no cambió.
     *
     * Se excluyen los productos cuya cantidad comprada neta es 0 (por ejemplo, una compra y su
     * devolución con cantidad negativa): dividir por cero daría `NULL` en MySQL y una excepción
     * en otros motores, y el resultado correcto para ese caso es "no hay costo derivable" → 0 por
     * el `COALESCE` del que consume el join.
     */
    public function subconsulta(): Builder
    {
        return DB::table('compra_items')
            ->join('compras', 'compras.id', '=', 'compra_items.compra_id')
            ->whereNull('compras.deleted_at')
            ->whereNotNull('compra_items.producto_id')
            ->groupBy('compra_items.producto_id')
            ->havingRaw('SUM(compra_items.cantidad) <> 0')
            ->selectRaw(
                'compra_items.producto_id as producto_id, '.
                'SUM(compra_items.precio_unitario * compra_items.cantidad) / SUM(compra_items.cantidad) as costo_promedio'
            );
    }

    /**
     * Expresión del CMV de una línea: el costo que corresponda × la cantidad de esa línea.
     *
     * @param  string  $columnaCantidad  expresión SQL de la cantidad (ya con el signo de la nota)
     * @param  string|null  $columnaCostoCongelado  expresión SQL de la columna de costo congelado
     *                                              (p. ej. `venta_items.costo_unitario`). Con
     *                                              `null` el comportamiento es el previo a la
     *                                              spec 075, para no romper consumidores viejos.
     */
    public function sqlCmv(string $columnaCantidad, ?string $columnaCostoCongelado = null): string
    {
        // Sin `NULLIF`: `costo_unitario = 0` es un costo congelado válido que tiene que ganarle al
        // promedio de compras (invariante I2). Envolverlo en `NULLIF(..., 0)` reintroduce el bug
        // que esta spec vino a corregir.
        $costo = $columnaCostoCongelado === null
            ? 'COALESCE('.self::ALIAS.'.costo_promedio, 0)'
            : 'COALESCE('.$columnaCostoCongelado.', '.self::ALIAS.'.costo_promedio, 0)';

        return $costo.' * ('.$columnaCantidad.')';
    }
}
