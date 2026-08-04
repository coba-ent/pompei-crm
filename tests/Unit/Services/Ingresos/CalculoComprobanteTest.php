<?php

namespace Tests\Unit\Services\Ingresos;

use App\Services\Ingresos\CalculoComprobante;
use PHPUnit\Framework\TestCase;

class CalculoComprobanteTest extends TestCase
{
    /** Caso real: Venta 0001-00016359 — 3 ítems al 21%, descuento general 15% (research.md §1). */
    public function test_descuento_general_se_aplica_proporcionalmente_a_neto_e_iva(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 157879.22, 'iva_pct' => '21'],
            ['descripcion' => 'Item 2', 'cantidad' => 1, 'precio_unitario' => 49859.48, 'iva_pct' => '21'],
            ['descripcion' => 'Item 3', 'cantidad' => 1, 'precio_unitario' => 91308.22, 'iva_pct' => '21'],
        ], 15);

        $this->assertEqualsWithDelta(299046.92, $resultado['subtotal_sin_descuento'], 0.01);
        $this->assertEqualsWithDelta(254189.89, $resultado['subtotal_con_descuento'], 0.01);
        $this->assertEqualsWithDelta(307569.76, $resultado['total'], 0.01);

        // El IVA implícito del total queda proporcional al neto YA descontado (21% de subtotal_con_descuento),
        // no del neto sin descontar — condición que rompía spec 042 (ValidadorDatosFiscales).
        $ivaImplicito = $resultado['total'] - $resultado['subtotal_con_descuento'];
        $this->assertEqualsWithDelta($resultado['subtotal_con_descuento'] * 0.21, $ivaImplicito, 0.05);
    }

    /** No-regresión: sin descuento general, el resultado debe ser exactamente el de antes del fix. */
    public function test_sin_descuento_general_no_cambia_el_resultado(): void
    {
        $items = [
            ['descripcion' => 'Item 1', 'cantidad' => 2, 'precio_unitario' => 500, 'descuento_pct' => 10, 'iva_pct' => '21'],
            ['descripcion' => 'Item 2', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '10.5'],
        ];

        $sinInformar = (new CalculoComprobante)->calcular($items, null);
        $conCero = (new CalculoComprobante)->calcular($items, 0);

        foreach ([$sinInformar, $conCero] as $resultado) {
            $this->assertSame(900.0, $resultado['items'][0]['subtotal']);
            $this->assertSame(1089.0, $resultado['items'][0]['subtotal_con_iva']);
            $this->assertSame(1000.0, $resultado['items'][1]['subtotal']);
            $this->assertSame(1105.0, $resultado['items'][1]['subtotal_con_iva']);
            $this->assertSame(1900.0, $resultado['subtotal_sin_descuento']);
            $this->assertSame(0.0, $resultado['descuento']);
            $this->assertSame(1900.0, $resultado['subtotal_con_descuento']);
            $this->assertSame(2194.0, $resultado['total']);
        }
    }

    /** Múltiples alícuotas: cada alícuota queda consistente entre sí (IVA proporcional al neto de esa misma alícuota). */
    public function test_multiples_alicuotas_con_descuento_general_mantienen_proporcion_por_alicuota(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => '21%', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ['descripcion' => '10.5%', 'cantidad' => 1, 'precio_unitario' => 2000, 'iva_pct' => '10.5'],
        ], 15);

        $item21 = $resultado['items'][0];
        $item105 = $resultado['items'][1];

        $ivaItem21 = $item21['subtotal_con_iva'] - $item21['subtotal'];
        $this->assertEqualsWithDelta($item21['subtotal'] * 0.21, $ivaItem21, 0.02);

        $ivaItem105 = $item105['subtotal_con_iva'] - $item105['subtotal'];
        $this->assertEqualsWithDelta($item105['subtotal'] * 0.105, $ivaItem105, 0.02);
    }
}
