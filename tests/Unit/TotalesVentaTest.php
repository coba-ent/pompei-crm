<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Producto;
use App\Services\Ventas\Ventas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotalesVentaTest extends TestCase
{
    use RefreshDatabase;

    private function ventas(): Ventas
    {
        return app(Ventas::class);
    }

    public function test_total_suma_lineas_con_iva(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        // 2 × 100 = 200 subtotal; total con IVA 21% = 242.
        $venta = $this->ventas()->crear(
            ['cliente_id' => $cliente->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100, 'iva_pct' => 21]],
        );

        $this->assertEquals(200.00, (float) $venta->subtotal);
        $this->assertEquals(242.00, (float) $venta->total);
    }

    public function test_descuento_de_linea_y_general_y_adicionales(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        // Línea: 1 × 1000 con 10% desc = 900 subtotal; con IVA 21% = 1089.
        // Desc general 50% → 544.5; + percepciones 100 + imp 50 + intereses 10 = 704.5.
        $venta = $this->ventas()->crear(
            [
                'cliente_id' => $cliente->id,
                'fecha_emision' => '2026-07-18',
                'descuento_general_pct' => 50,
                'percepciones' => 100,
                'impuestos_internos' => 50,
                'intereses' => 10,
            ],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 1000, 'descuento_pct' => 10, 'iva_pct' => 21]],
        );

        $this->assertEquals(900.00, (float) $venta->subtotal);
        $this->assertEquals(704.50, (float) $venta->total);
    }

    public function test_total_nunca_negativo(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $venta = $this->ventas()->crear(
            [
                'cliente_id' => $cliente->id,
                'fecha_emision' => '2026-07-18',
                'descuento_general_pct' => 100,
            ],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100, 'iva_pct' => 0]],
        );

        $this->assertGreaterThanOrEqual(0, (float) $venta->total);
        $this->assertEquals(0.00, (float) $venta->total);
    }
}
