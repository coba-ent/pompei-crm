<?php

namespace App\Services\Stock;

use App\Models\Compra;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Matchea cada fila del informe de stock con la operación y el producto del CRM (spec 094).
 *
 * Trabaja POR LOTE, no por fila: precarga todo en memoria en el constructor. Una consulta por fila
 * serían más de 30.000 queries para una carga que se corre una sola vez.
 */
class ResolvedorOperacionLegacy
{
    /** @var array<string,int> legacy_id => venta_id */
    private array $ventas;

    /** @var array<string,int> legacy_id => compra_id */
    private array $compras;

    /** @var array<string,int> codigo => producto_id */
    private array $productosPorCodigo;

    /** @var array<int,array<int,int>> id numérico inicial del código => producto_ids candidatos */
    private array $productosPorIdNumerico = [];

    /** @var array<int,array<int,bool>> venta_id => [producto_id => true] */
    private array $itemsVenta = [];

    /** @var array<int,array<int,bool>> compra_id => [producto_id => true] */
    private array $itemsCompra = [];

    public function __construct()
    {
        $this->ventas = DB::table('ventas')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $this->compras = DB::table('compras')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $this->productosPorCodigo = DB::table('productos')->pluck('id', 'codigo')->all();

        // El `codigo` del CRM tiene formato "{id} {sku}" (ej. "12690 12690"), y el Excel trae el
        // mismo formato. El 97,5% matchea exacto; el resto necesita el número inicial como fallback.
        foreach ($this->productosPorCodigo as $codigo => $id) {
            if (preg_match('#^(\d+)\b#', (string) $codigo, $m)) {
                $this->productosPorIdNumerico[(int) $m[1]][] = $id;
            }
        }

        foreach (DB::table('venta_items')->select('venta_id', 'producto_id')->cursor() as $item) {
            $this->itemsVenta[$item->venta_id][$item->producto_id] = true;
        }

        foreach (DB::table('compra_items')->select('compra_id', 'producto_id')->cursor() as $item) {
            $this->itemsCompra[$item->compra_id][$item->producto_id] = true;
        }
    }

    /**
     * La operación del CRM a la que pertenece la fila, o null.
     *
     * @return array{0: class-string, 1: int}|null [origen_type, origen_id]
     */
    public function operacion(FilaInformeStock $fila): ?array
    {
        if ($fila->idOperacion === null) {
            return null;
        }

        if ($fila->esDeVenta()) {
            $id = $this->ventas["{$fila->anio}-FC-{$fila->idOperacion}"] ?? null;

            return $id === null ? null : [Venta::class, $id];
        }

        if ($fila->esDeCompra()) {
            $id = $this->compras["COMPRA-{$fila->anio}-FC-{$fila->idOperacion}"] ?? null;

            return $id === null ? null : [Compra::class, $id];
        }

        return null;
    }

    /**
     * El producto de la fila, o null si no se puede determinar sin adivinar.
     *
     * Tres estrategias, en orden de confianza:
     *
     * 1. Código exacto — cubre 30.720 de 31.518 filas.
     * 2. **Desambiguación por la operación** — si el código no matchea pero su número inicial tiene
     *    candidatos, gana el que efectivamente esté en los items de esa venta o compra.
     * 3. Número inicial con un único candidato.
     *
     * La estrategia 2 existe por un caso real: el comodín "99999" está DUPLICADO en la base — el id
     * 100000 (`30622 99999`, activo) tiene el stock, pero el id 100015 (`30622`, inactivo) es el que
     * usan 273 ventas legacy. El Excel lo trae como "30622 30622", que no coincide con ninguno.
     * Elegir "el que tiene stock" habría asignado 687 movimientos al producto equivocado.
     *
     * @param  array{0: class-string, 1: int}|null  $operacion
     */
    public function producto(FilaInformeStock $fila, ?array $operacion): ?int
    {
        if (isset($this->productosPorCodigo[$fila->codigo])) {
            return $this->productosPorCodigo[$fila->codigo];
        }

        if (! preg_match('#^(\d+)\b#', $fila->codigo, $m)) {
            return null;
        }

        $candidatos = $this->productosPorIdNumerico[(int) $m[1]] ?? [];

        if ($candidatos === []) {
            return null;
        }

        if ($operacion !== null) {
            $items = $operacion[0] === Venta::class
                ? ($this->itemsVenta[$operacion[1]] ?? [])
                : ($this->itemsCompra[$operacion[1]] ?? []);

            foreach ($candidatos as $candidato) {
                if (isset($items[$candidato])) {
                    return $candidato;
                }
            }
        }

        // Sin operación que desambigüe, sólo se acepta si no hay ambigüedad posible.
        return count($candidatos) === 1 ? $candidatos[0] : null;
    }
}
