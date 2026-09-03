<?php

namespace Tests\Unit;

use App\Models\NotaCreditoDebito;
use App\Models\NotaCreditoDebitoItem;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Casos de contrato de `montoDescuentoGeneral()` (spec 098, contracts/metodos-modelo.md): replica
 * en PHP el mismo algoritmo que `notas-credito-debito.js::recalcular()` calcula client-side, para
 * que la fila nueva del PDF (US3) nunca diverja del `monto` que ya se guardó. Se corre sobre
 * instancias en memoria, sin persistir.
 */
class NotaCreditoDebitoMontoDescuentoGeneralTest extends TestCase
{
    private function item(float $cantidad, float $precio, float $descuentoPct = 0): NotaCreditoDebitoItem
    {
        $item = new NotaCreditoDebitoItem();
        $item->cantidad = $cantidad;
        $item->precio = $precio;
        $item->descuento_pct = $descuentoPct;

        return $item;
    }

    private function nota(Collection $items, string $tipo, ?float $valor): NotaCreditoDebito
    {
        $nota = new NotaCreditoDebito();
        $nota->descuento_general_tipo = $tipo;
        $nota->descuento_general_pct = $tipo === 'porcentaje' ? $valor : null;
        $nota->descuento_general_monto = $tipo === 'monto' ? $valor : null;
        $nota->setRelation('items', $items);

        return $nota;
    }

    public function test_porcentaje_10_sobre_subtotal_1000(): void
    {
        $items = collect([$this->item(1, 1000)]);
        $nota = $this->nota($items, 'porcentaje', 10);

        $this->assertEqualsWithDelta(100.0, $nota->montoDescuentoGeneral(), 0.01);
    }

    public function test_monto_fijo_150_sobre_subtotal_1000(): void
    {
        $items = collect([$this->item(1, 1000)]);
        $nota = $this->nota($items, 'monto', 150);

        $this->assertEqualsWithDelta(150.0, $nota->montoDescuentoGeneral(), 0.01);
    }

    public function test_monto_fijo_sin_items_no_divide_por_cero(): void
    {
        $items = collect();
        $nota = $this->nota($items, 'monto', 150);

        $this->assertEqualsWithDelta(0.0, $nota->montoDescuentoGeneral(), 0.01);
    }

    public function test_sin_descuento_general_da_cero(): void
    {
        $items = collect([$this->item(1, 1000)]);
        $nota = $this->nota($items, 'porcentaje', null);

        $this->assertEqualsWithDelta(0.0, $nota->montoDescuentoGeneral(), 0.01);
    }

    public function test_descuento_de_linea_se_descuenta_antes_del_general(): void
    {
        // Línea con 10% propio: subtotal de línea = 900. Descuento general 10% sobre eso = 90.
        $items = collect([$this->item(1, 1000, 10)]);
        $nota = $this->nota($items, 'porcentaje', 10);

        $this->assertEqualsWithDelta(90.0, $nota->montoDescuentoGeneral(), 0.01);
    }
}
