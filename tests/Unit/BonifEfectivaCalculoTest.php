<?php

namespace Tests\Unit;

use App\Models\CompraItem;
use App\Models\PresupuestoItem;
use App\Models\VentaItem;
use App\Services\Ingresos\CalculoComprobante;
use Tests\TestCase;

/**
 * Casos de contrato de `bonifEfectivaPct()` / `bonifEfectivaEtiqueta()` (spec 098,
 * contracts/metodos-modelo.md), idénticos en los 3 modelos de ítem — se corren sobre instancias
 * en memoria, sin persistir: el cálculo sólo depende de `cantidad`, `precio_unitario` y
 * `subtotal`, los tres ya cargados sin necesidad de tocar la base de datos.
 */
class BonifEfectivaCalculoTest extends TestCase
{
    /** @return array<class-string> */
    public static function modelosItem(): array
    {
        return [
            'PresupuestoItem' => [PresupuestoItem::class],
            'VentaItem' => [VentaItem::class],
            'CompraItem' => [CompraItem::class],
        ];
    }

    private function item(string $clase, float $cantidad, float $precio, float $subtotal)
    {
        $item = new $clase();
        $item->cantidad = $cantidad;
        $item->precio_unitario = $precio;
        $item->subtotal = $subtotal;

        return $item;
    }

    /** @dataProvider modelosItem */
    public function test_sin_ningun_descuento(string $clase): void
    {
        $item = $this->item($clase, 1, 100, 100);

        $this->assertEqualsWithDelta(0.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('-', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_solo_descuento_de_linea(string $clase): void
    {
        $item = $this->item($clase, 1, 100, 90);

        $this->assertEqualsWithDelta(10.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('10%', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_linea_y_general_combinados_no_son_aditivos(string $clase): void
    {
        // 10% de línea + 10% general: subtotal = 100 * 0.9 * 0.9 = 81, no 100 * 0.8 = 80.
        $item = $this->item($clase, 1, 100, 81);

        $this->assertEqualsWithDelta(19.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('19%', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_cantidad_cero_no_divide_por_cero(string $clase): void
    {
        $item = $this->item($clase, 0, 100, 0);

        $this->assertEqualsWithDelta(0.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('-', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_precio_cero_no_divide_por_cero(string $clase): void
    {
        $item = $this->item($clase, 1, 0, 0);

        $this->assertEqualsWithDelta(0.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('-', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_cantidad_mayor_a_uno(string $clase): void
    {
        // Bruto = 2 * 50 = 100, subtotal 90 -> 10%.
        $item = $this->item($clase, 2, 50, 90);

        $this->assertEqualsWithDelta(10.0, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('10%', $item->bonifEfectivaEtiqueta());
    }

    /** @dataProvider modelosItem */
    public function test_porcentaje_con_decimales_usa_coma_argentina(string $clase): void
    {
        // Subtotal 87.5 sobre bruto 100 -> 12,5% de bonificación.
        $item = $this->item($clase, 1, 100, 87.5);

        $this->assertEqualsWithDelta(12.5, $item->bonifEfectivaPct(), 0.001);
        $this->assertSame('12,5%', $item->bonifEfectivaEtiqueta());
    }

    /**
     * research.md Decisión 4: el redondeo de `bonifEfectivaPct()` no puede introducir una
     * segunda fuente de verdad que diverja de `CalculoComprobante` — se corre el servicio REAL
     * (no un mock) con 5 ítems y un Descuento General no redondo (7%), y se verifica que la suma
     * de los `subtotal` que produce coincide, dentro de 1 centavo, con `subtotal_con_descuento`.
     * `bonifEfectivaPct()` en sí no participa de esta cuenta (compara contra el bruto de cada
     * línea, no contra la suma), pero esto confirma que la fuente de la que se deriva —`subtotal`—
     * sigue siendo consistente con el total, que es la garantía que exige SC-001.
     */
    public function test_suma_de_subtotales_de_linea_coincide_con_el_total_dentro_de_un_centavo(): void
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = [
                'descripcion' => "Ítem {$i}",
                'cantidad' => $i,
                'precio_unitario' => 33.33 * $i,
                'descuento_pct' => $i % 2 === 0 ? 5 : 0,
                'iva_pct' => '21',
            ];
        }

        $resultado = (new CalculoComprobante())->calcular($items, 'porcentaje', 7);

        $sumaSubtotales = array_sum(array_column($resultado['items'], 'subtotal'));

        $this->assertEqualsWithDelta(
            $resultado['subtotal_con_descuento'],
            $sumaSubtotales,
            0.01
        );
    }
}
