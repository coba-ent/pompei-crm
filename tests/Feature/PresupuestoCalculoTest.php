<?php

namespace Tests\Feature;

use App\Services\Ingresos\CalculoComprobante;
use Tests\TestCase;

/** US1 — CalculoComprobante: subtotal/IVA/descuento/percepciones (SC-001). */
class PresupuestoCalculoTest extends TestCase
{
    protected bool $autenticado = false;

    public function test_calcula_subtotal_iva_y_total_de_un_item_simple(): void
    {
        $resultado = (new CalculoComprobante())->calcular([
            ['descripcion' => 'Camisa', 'cantidad' => 2, 'precio_unitario' => 100, 'iva_pct' => '21'],
        ], null);

        $this->assertSame(200.0, $resultado['subtotal_sin_descuento']);
        $this->assertSame(0.0, $resultado['descuento']);
        $this->assertSame(200.0, $resultado['subtotal_con_descuento']);
        $this->assertSame(242.0, $resultado['total']); // 200 + 21%
        $this->assertSame(242.0, $resultado['items'][0]['subtotal_con_iva']);
    }

    public function test_descuento_por_item_y_descuento_general_se_acumulan(): void
    {
        $resultado = (new CalculoComprobante())->calcular([
            ['descripcion' => 'Producto A', 'cantidad' => 1, 'precio_unitario' => 1000, 'descuento_pct' => 10, 'iva_pct' => '21'],
        ], 10); // 10% general sobre el subtotal ya neto del descuento de ítem

        // Subtotal del ítem tras 10% de descuento propio: 900.
        $this->assertSame(900.0, $resultado['subtotal_sin_descuento']);
        $this->assertSame(90.0, $resultado['descuento']); // 10% de 900
        $this->assertSame(810.0, $resultado['subtotal_con_descuento']);
        // Total = subtotal_con_iva (900 + 21% = 1089) - descuento general (90)
        $this->assertSame(999.0, $resultado['total']);
    }

    public function test_percepciones_impuestos_e_intereses_suman_al_total(): void
    {
        $resultado = (new CalculoComprobante())->calcular([
            ['descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'iva_pct' => 'exento'],
        ], null, [
            ['tipo' => 'percepcion', 'concepto' => 'IIBB', 'monto' => 15],
            ['tipo' => 'interes', 'concepto' => 'Financiación', 'monto' => 5],
        ]);

        $this->assertSame(100.0, $resultado['subtotal_sin_descuento']);
        $this->assertSame(120.0, $resultado['total']); // 100 (exento) + 15 + 5
    }
}
