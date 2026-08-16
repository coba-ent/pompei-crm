<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Costo de Mercadería Vendida: costo promedio ponderado de compras, por producto (spec 068, R2).
 *
 * ## Por qué es una derivación y no una columna
 *
 * El CRM **no guarda el costo histórico de cada movimiento** (`movimientos_stock` no tiene columna
 * de costo), así que el costo real al que salió cada unidad vendida no es reconstruible. La única
 * derivación compatible con lo que muestra Contagram es el promedio ponderado de **las compras
 * registradas** del producto:
 *
 * ```
 * costo_promedio(producto) = SUM(precio_unitario × cantidad) / SUM(cantidad)
 * ```
 *
 * sobre compras y `compra_items` no eliminados, **sin recorte de fecha** (el promedio es del
 * producto, no del período consultado). Un producto sin ninguna compra registrada aporta 0 — es
 * exactamente lo que se observó en el relevamiento: los ítems del Id 5 tienen "Costo Total Actual"
 * mayor a cero y CMV en cero porque esos productos nunca se compraron.
 *
 * ## Por qué NO es lo mismo que "Costo Actual"
 *
 * "Costo Actual" usa `productos.costo`, el costo **vigente hoy**, y se mueve cada vez que alguien
 * edita la ficha del producto. El CMV mira hacia atrás, a lo que efectivamente se pagó. Que las
 * dos columnas puedan dar distinto sobre el mismo producto no es un error del informe: es su
 * razón de existir (FR-013 / FR-014), y tiene test dedicado.
 *
 * ## Forma de uso
 *
 * Se resuelve con un `LEFT JOIN` a una subconsulta **agrupada una sola vez**, no con una
 * correlacionada por fila: con 5.000 ventas la segunda forma no sostiene SC-002 (research R9).
 */
class CostoMercaderiaVendida
{
    /** Alias con el que la subconsulta entra en el `LEFT JOIN` de los informes. */
    public const ALIAS = 'costo_compras';

    /**
     * Subconsulta `producto_id → costo_promedio`, lista para `leftJoinSub()`.
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
     * Expresión del CMV de una línea: costo promedio × la cantidad de esa línea.
     *
     * @param  string  $columnaCantidad  expresión SQL de la cantidad (ya con el signo de la nota)
     */
    public function sqlCmv(string $columnaCantidad): string
    {
        return 'COALESCE('.self::ALIAS.'.costo_promedio, 0) * ('.$columnaCantidad.')';
    }
}
