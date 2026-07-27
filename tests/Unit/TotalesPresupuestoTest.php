<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Producto;
use App\Services\Presupuestos\Presupuestos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotalesPresupuestoTest extends TestCase
{
    use RefreshDatabase;

    public function test_subtotal_y_total_se_calculan_desde_las_lineas(): void
    {
        $cliente = Cliente::factory()->create();
        $p1 = Producto::factory()->create(['tipo' => 'servicio']);
        $p2 = Producto::factory()->create(['tipo' => 'servicio']);

        $servicio = app(Presupuestos::class);

        // Línea 1: 2 × 100 con 10% desc = 180 neto, +21% iva = 217.80
        // Línea 2: 1 × 50 sin desc = 50 neto, +21% iva = 60.50
        $presupuesto = $servicio->crear([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'descuento_general_pct' => 10,
        ], [
            ['producto_id' => $p1->id, 'cantidad' => 2, 'precio' => 100, 'descuento_pct' => 10, 'iva_pct' => 21],
            ['producto_id' => $p2->id, 'cantidad' => 1, 'precio' => 50, 'iva_pct' => 21],
        ]);

        // subtotal = Σ neto = 180 + 50 = 230
        $this->assertEqualsWithDelta(230.00, (float) $presupuesto->subtotal, 0.01);

        // total = (217.80 + 60.50) × (1 - 10%) = 278.30 × 0.9 = 250.47
        $this->assertEqualsWithDelta(250.47, (float) $presupuesto->total, 0.01);
    }

    public function test_descuento_general_no_deja_el_total_negativo(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $servicio = app(Presupuestos::class);

        $presupuesto = $servicio->crear([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'descuento_general_pct' => 100,
        ], [
            ['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100, 'iva_pct' => 21],
        ]);

        $this->assertGreaterThanOrEqual(0, (float) $presupuesto->total);
        $this->assertEqualsWithDelta(0.0, (float) $presupuesto->total, 0.01);
    }
}
