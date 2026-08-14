<?php

namespace App\Services\Ingresos;

use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Stock\StockService;
use Illuminate\Support\Collection;

/**
 * Cierra la brecha de research.md §R1: las Ventas del CRM no descontaban stock.
 * Único punto que conecta el ciclo de vida de una Venta con StockService, para
 * Ventas manuales y para las originadas en Mercado Libre por igual (plan.md
 * Constitution Check — un solo comportamiento para el mismo documento).
 */
class StockDeVenta
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** Alta de Venta: descuenta stock de cada ítem que corresponda (FR-046, FR-046c). */
    public function aplicarAlta(Venta $venta): void
    {
        $items = $this->itemsQueMuevenStock($venta->items);

        if ($items->isEmpty()) {
            return;
        }

        $deposito = $this->resolverDeposito($venta);

        foreach ($items as $item) {
            $this->stock->registrarSalida($item->producto, null, $deposito, (float) $item->cantidad, $venta);
        }
    }

    /** Borrado lógico de Venta: reintegra el stock descontado (FR-046b). */
    public function reintegrarPorEliminacion(Venta $venta): void
    {
        $items = $this->itemsQueMuevenStock($venta->items);

        if ($items->isEmpty()) {
            return;
        }

        $deposito = $this->resolverDeposito($venta);

        foreach ($items as $item) {
            $this->stock->registrarEntrada($item->producto, null, $deposito, (float) $item->cantidad, $venta);
        }
    }

    /**
     * Edición de Venta: reintegra las cantidades anteriores y aplica las nuevas
     * (FR-046b). $itemsAnteriores debe capturarse ANTES de borrar los ítems
     * viejos del formulario; los nuevos ya deben estar persistidos en
     * $venta->items al momento de llamar este método.
     */
    public function reaplicarPorEdicion(Venta $venta, Collection $itemsAnteriores, ?Deposito $depositoAnterior = null): void
    {
        $anteriores = $this->itemsQueMuevenStock($itemsAnteriores);

        if ($anteriores->isNotEmpty()) {
            $deposito = $depositoAnterior ?? $this->resolverDeposito($venta);

            foreach ($anteriores as $item) {
                $this->stock->registrarEntrada($item->producto, null, $deposito, (float) $item->cantidad, $venta);
            }
        }

        $this->aplicarAlta($venta);
    }

    /** Sólo ítems con producto asociado y de tipo Producto controlan stock (FR-046a). */
    private function itemsQueMuevenStock(Collection $items): Collection
    {
        return $items->filter(fn (VentaItem $item) => $item->producto && $item->producto->controlaStock());
    }

    /** ML/TN → depósito configurado (o el por defecto); manual → depósito por defecto del CRM (FR-047). */
    private function resolverDeposito(Venta $venta): Deposito
    {
        if ($venta->origen === 'mercadolibre') {
            // spec 065/FR-020b: manda el depósito YA imputado a la Venta, no un recálculo.
            // Desde que una orden íntegramente Full se imputa al depósito Full, recalcular
            // acá descontaría del depósito general una venta contabilizada en el Full.
            // Sin depósito en la Venta (las anteriores a que existiera la columna) se cae
            // al configurado, que es lo que se venía haciendo siempre.
            return $venta->deposito ?? MercadoLibreConfiguracion::actual()->depositoEfectivo();
        }

        if ($venta->origen === 'tiendanube') {
            return TiendanubeConexionRest::actual()->depositoEfectivo();
        }

        return $venta->deposito ?? $this->depositoPorDefecto();
    }

    /** Ver Deposito::porDefecto() — única definición de "depósito por defecto del CRM". */
    private function depositoPorDefecto(): Deposito
    {
        return Deposito::porDefecto() ?? throw new \RuntimeException('No hay ningún depósito activo.');
    }
}
