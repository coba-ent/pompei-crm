<?php

namespace App\Services\Egresos;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Deposito;
use App\Services\Stock\StockService;
use Illuminate\Support\Collection;

/**
 * Cierra la brecha de research.md §R1 (spec 030): las Compras del CRM no
 * sumaban stock. Análogo a App\Services\Ingresos\StockDeVenta pero en sentido
 * de entrada de stock en vez de salida.
 */
class StockDeCompra
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** Alta de Compra: suma stock de cada ítem que corresponda (FR-001, FR-008). */
    public function aplicarAlta(Compra $compra): void
    {
        $items = $this->itemsQueMuevenStock($compra->items);

        if ($items->isEmpty()) {
            return;
        }

        $deposito = $compra->deposito ?? $this->depositoPorDefecto();

        foreach ($items as $item) {
            $cantidad = (float) $item->cantidad;

            if ($cantidad > 0) {
                $this->stock->registrarEntrada($item->producto, null, $deposito, $cantidad, $compra, fecha: $compra->fecha_emision->toDateString());
            } else {
                $this->stock->registrarSalida($item->producto, null, $deposito, abs($cantidad), $compra, fecha: $compra->fecha_emision->toDateString());
            }
        }
    }

    /** Borrado lógico de Compra: reintegra (resta) el stock que había sumado (FR-004). */
    public function reintegrarPorEliminacion(Compra $compra): void
    {
        $items = $this->itemsQueMuevenStock($compra->items);

        if ($items->isEmpty()) {
            return;
        }

        $deposito = $compra->deposito ?? $this->depositoPorDefecto();

        foreach ($items as $item) {
            $cantidad = (float) $item->cantidad;

            if ($cantidad > 0) {
                $this->stock->registrarSalida($item->producto, null, $deposito, $cantidad, $compra);
            } else {
                $this->stock->registrarEntrada($item->producto, null, $deposito, abs($cantidad), $compra);
            }
        }
    }

    /**
     * Edición de Compra: reintegra las cantidades anteriores y aplica las
     * nuevas (FR-003). $itemsAnteriores debe capturarse ANTES de borrar los
     * ítems viejos del formulario; los nuevos ya deben estar persistidos en
     * $compra->items al momento de llamar este método.
     */
    public function reaplicarPorEdicion(Compra $compra, Collection $itemsAnteriores, ?Deposito $depositoAnterior = null): void
    {
        $anteriores = $this->itemsQueMuevenStock($itemsAnteriores);

        if ($anteriores->isNotEmpty()) {
            $deposito = $depositoAnterior ?? $compra->deposito ?? $this->depositoPorDefecto();

            foreach ($anteriores as $item) {
                $cantidad = (float) $item->cantidad;

                if ($cantidad > 0) {
                    $this->stock->registrarSalida($item->producto, null, $deposito, $cantidad, $compra);
                } else {
                    $this->stock->registrarEntrada($item->producto, null, $deposito, abs($cantidad), $compra);
                }
            }
        }

        $this->aplicarAlta($compra);
    }

    /** Sólo ítems con producto asociado y de tipo Producto controlan stock (FR-002). */
    private function itemsQueMuevenStock(Collection $items): Collection
    {
        return $items->filter(fn (CompraItem $item) => $item->producto && $item->producto->controlaStock());
    }

    /** Ver Deposito::porDefecto() — única definición de "depósito por defecto del CRM" (FR-006). */
    private function depositoPorDefecto(): Deposito
    {
        return Deposito::porDefecto() ?? throw new \RuntimeException('No hay ningún depósito activo.');
    }
}
