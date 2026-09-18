<?php

namespace Tests\Unit\Services\Ingresos;

use App\Services\Ingresos\CalculoComprobante;
use PHPUnit\Framework\TestCase;

class CalculoComprobanteTest extends TestCase
{
    /**
     * Caso real: Venta 0001-00016359 — 3 ítems al 21%, descuento general 15% (research.md §1).
     *
     * El total esperado pasó de $307.569,76 a **$307.569,77** con la spec 104, y el centavo es el
     * arreglo, no una regresión: sobre un neto declarado de $254.189,89, ARCA calcula un IVA de
     * $53.379,88, y el valor viejo declaraba $53.379,87. El total nuevo es el único que cierra
     * contra el recálculo de ARCA.
     */
    public function test_descuento_general_se_aplica_proporcionalmente_a_neto_e_iva(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 157879.22, 'iva_pct' => '21'],
            ['descripcion' => 'Item 2', 'cantidad' => 1, 'precio_unitario' => 49859.48, 'iva_pct' => '21'],
            ['descripcion' => 'Item 3', 'cantidad' => 1, 'precio_unitario' => 91308.22, 'iva_pct' => '21'],
        ], 'porcentaje', 15);

        $this->assertEqualsWithDelta(299046.92, $resultado['subtotal_sin_descuento'], 0.01);
        $this->assertEqualsWithDelta(254189.89, $resultado['subtotal_con_descuento'], 0.01);
        $this->assertEqualsWithDelta(307569.77, $resultado['total'], 0.01);

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

        $sinInformar = (new CalculoComprobante)->calcular($items, 'porcentaje', null);
        $conCero = (new CalculoComprobante)->calcular($items, 'porcentaje', 0);

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
        ], 'porcentaje', 15);

        $item21 = $resultado['items'][0];
        $item105 = $resultado['items'][1];

        $ivaItem21 = $item21['subtotal_con_iva'] - $item21['subtotal'];
        $this->assertEqualsWithDelta($item21['subtotal'] * 0.21, $ivaItem21, 0.02);

        $ivaItem105 = $item105['subtotal_con_iva'] - $item105['subtotal'];
        $this->assertEqualsWithDelta($item105['subtotal'] * 0.105, $ivaItem105, 0.02);
    }

    /** Modo monto: una sola alícuota, el monto fijo equivale al % que representa sobre el subtotal bruto. */
    public function test_descuento_general_modo_monto_una_alicuota(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
        ], 'monto', 500);

        // 500 / 10000 = 5% efectivo
        $this->assertEqualsWithDelta(10000.0, $resultado['subtotal_sin_descuento'], 0.01);
        $this->assertEqualsWithDelta(500.0, $resultado['descuento'], 0.01);
        $this->assertEqualsWithDelta(9500.0, $resultado['subtotal_con_descuento'], 0.01);
        $this->assertEqualsWithDelta(11495.0, $resultado['total'], 0.01);
    }

    /** Modo monto: dos alícuotas, el prorrateo del monto fijo respeta la proporción de cada alícuota. */
    public function test_descuento_general_modo_monto_dos_alicuotas(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => '21%', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ['descripcion' => '10.5%', 'cantidad' => 1, 'precio_unitario' => 2000, 'iva_pct' => '10.5'],
        ], 'monto', 450);

        // 450 / 3000 = 15% efectivo, mismo resultado que el test de %15 con estos ítems.
        $item21 = $resultado['items'][0];
        $item105 = $resultado['items'][1];

        $ivaItem21 = $item21['subtotal_con_iva'] - $item21['subtotal'];
        $this->assertEqualsWithDelta($item21['subtotal'] * 0.21, $ivaItem21, 0.02);

        $ivaItem105 = $item105['subtotal_con_iva'] - $item105['subtotal'];
        $this->assertEqualsWithDelta($item105['subtotal'] * 0.105, $ivaItem105, 0.02);

        $this->assertEqualsWithDelta(450.0, $resultado['descuento'], 0.02);
    }

    /** Modo monto con subtotal $0: no debe dividir por cero, descuento efectivo queda en 0. */
    public function test_descuento_general_modo_monto_subtotal_cero(): void
    {
        $resultado = (new CalculoComprobante)->calcular([], 'monto', 500);

        $this->assertSame(0.0, $resultado['subtotal_sin_descuento']);
        $this->assertSame(0.0, $resultado['descuento']);
        $this->assertSame(0.0, $resultado['subtotal_con_descuento']);
        $this->assertSame(0.0, $resultado['total']);
    }

    /** Borde válido: monto fijo igual al subtotal — descuento del 100%, total queda en 0. */
    public function test_descuento_general_modo_monto_igual_al_subtotal(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ], 'monto', 1000);

        $this->assertEqualsWithDelta(1000.0, $resultado['subtotal_sin_descuento'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $resultado['descuento'], 0.01);
        $this->assertEqualsWithDelta(0.0, $resultado['subtotal_con_descuento'], 0.01);
        $this->assertEqualsWithDelta(0.0, $resultado['total'], 0.01);
    }

    /**
     * Caso real: Venta 25191 (FLORDANA S R L, Factura A, 18/09/2026) — 4 ítems al 21% con
     * descuento general del 15%. ARCA la rechazó con "El IVA calculado no coincide con la suma por
     * alícuota".
     *
     * El IVA que ARCA recalcula es `round(neto × alícuota, 2)` **por línea**, sobre el neto que se
     * le declara. Este test exige esa igualdad **exacta** (sin delta): con un delta de 0,02 —como
     * usan los tests de arriba— el bug pasaba desapercibido, porque el desvío era de un centavo por
     * línea.
     */
    public function test_venta_25191_el_iva_por_linea_cierra_exacto_contra_el_neto(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 465170.0, 'iva_pct' => '21'],
            ['descripcion' => 'Item 2', 'cantidad' => 1, 'precio_unitario' => 247812.52, 'iva_pct' => '21'],
            ['descripcion' => 'Item 3', 'cantidad' => 4, 'precio_unitario' => 221817.26, 'iva_pct' => '21'],
            ['descripcion' => 'Item 4', 'cantidad' => 1, 'precio_unitario' => 392243.99, 'iva_pct' => '21'],
        ], 'porcentaje', 15);

        foreach ($resultado['items'] as $i => $item) {
            $ivaGuardado = round($item['subtotal_con_iva'] - $item['subtotal'], 2);
            $ivaSegunArca = round($item['subtotal'] * 21 / 100, 2);

            $this->assertSame(
                $ivaSegunArca,
                $ivaGuardado,
                "El ítem {$i} declara un IVA que ARCA no reconoce: neto {$item['subtotal']}, ".
                "guardado {$ivaGuardado}, esperado {$ivaSegunArca}."
            );
        }
    }

    /**
     * El IVA por línea cierra exacto en todas las alícuotas del catálogo, no sólo al 21%.
     *
     * Son las de `Producto::OPCIONES_IVA`, que es lo que el CRM puede cargar. ARCA admite además
     * 2,5% —`MapeadorComprobante::ALICUOTAS_IVA` la mapea— pero ningún producto puede tenerla:
     * `porcentajeIva()` devuelve 0 para cualquier clave fuera del catálogo.
     */
    public function test_el_iva_por_linea_cierra_exacto_en_todas_las_alicuotas(): void
    {
        foreach (['5', '10.5', '21', '27'] as $alicuota) {
            $resultado = (new CalculoComprobante)->calcular([
                ['descripcion' => "A {$alicuota}", 'cantidad' => 3, 'precio_unitario' => 333407.39, 'iva_pct' => $alicuota],
                ['descripcion' => "B {$alicuota}", 'cantidad' => 1, 'precio_unitario' => 210640.64, 'iva_pct' => $alicuota],
            ], 'porcentaje', 15);

            foreach ($resultado['items'] as $item) {
                $this->assertSame(
                    round($item['subtotal'] * (float) $alicuota / 100, 2),
                    round($item['subtotal_con_iva'] - $item['subtotal'], 2),
                    "Alícuota {$alicuota}% no cierra sobre un neto de {$item['subtotal']}."
                );
            }
        }
    }

    /** Descuento por línea + general combinados: el IVA sale del neto final, ya con los dos aplicados. */
    public function test_descuento_por_linea_y_general_combinados_cierran_exacto(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Con desc. línea', 'cantidad' => 2, 'precio_unitario' => 158377.53, 'descuento_pct' => 12, 'iva_pct' => '21'],
            ['descripcion' => 'Sin desc. línea', 'cantidad' => 1, 'precio_unitario' => 70015.56, 'iva_pct' => '10.5'],
        ], 'porcentaje', 15);

        $this->assertSame(
            round($resultado['items'][0]['subtotal'] * 0.21, 2),
            round($resultado['items'][0]['subtotal_con_iva'] - $resultado['items'][0]['subtotal'], 2)
        );
        $this->assertSame(
            round($resultado['items'][1]['subtotal'] * 0.105, 2),
            round($resultado['items'][1]['subtotal_con_iva'] - $resultado['items'][1]['subtotal'], 2)
        );
    }

    /** Descuento general en MONTO: mismo criterio, el monto se convierte a % antes de aplicarse. */
    public function test_descuento_general_en_monto_tambien_cierra_exacto(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Item 1', 'cantidad' => 1, 'precio_unitario' => 392243.99, 'iva_pct' => '21'],
            ['descripcion' => 'Item 2', 'cantidad' => 4, 'precio_unitario' => 221817.26, 'iva_pct' => '21'],
        ], 'monto', 190000);

        foreach ($resultado['items'] as $item) {
            $this->assertSame(
                round($item['subtotal'] * 0.21, 2),
                round($item['subtotal_con_iva'] - $item['subtotal'], 2)
            );
        }
    }

    /** Una línea negativa (devolución dentro del comprobante) conserva el signo y sigue cerrando. */
    public function test_linea_negativa_conserva_signo_y_cierra_exacto(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Positiva', 'cantidad' => 1, 'precio_unitario' => 333407.39, 'iva_pct' => '21'],
            ['descripcion' => 'Negativa', 'cantidad' => -1, 'precio_unitario' => 70015.56, 'iva_pct' => '21'],
        ], 'porcentaje', 15);

        $this->assertLessThan(0, $resultado['items'][1]['subtotal']);

        foreach ($resultado['items'] as $item) {
            $this->assertSame(
                round($item['subtotal'] * 0.21, 2),
                round($item['subtotal_con_iva'] - $item['subtotal'], 2)
            );
        }
    }

    /** Alícuota 0% (exento): el con-IVA queda igual al neto, sin centavo de más. */
    public function test_alicuota_cero_no_agrega_iva(): void
    {
        $resultado = (new CalculoComprobante)->calcular([
            ['descripcion' => 'Exento', 'cantidad' => 3, 'precio_unitario' => 333407.39, 'iva_pct' => '0'],
        ], 'porcentaje', 15);

        $item = $resultado['items'][0];
        $this->assertSame($item['subtotal'], $item['subtotal_con_iva']);
    }
}
