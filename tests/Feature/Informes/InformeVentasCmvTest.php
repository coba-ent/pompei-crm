<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Producto;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 068 — Costo de Mercadería Vendida (FR-014, research R2).
 *
 * El CMV es la cifra más frágil del Informe de Ventas: el CRM no guarda el costo histórico por
 * movimiento, así que se **deriva** del promedio ponderado de las compras del producto. Una
 * redacción vaga acá se traduce directamente en números distintos a los de Contagram, así que la
 * derivación queda fijada por escrito.
 */
class InformeVentasCmvTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->autenticarConPermisoInformes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function informe(): VentasInformeQuery
    {
        return app(VentasInformeQuery::class);
    }

    /** Compra de `$cantidad` unidades de `$producto` a `$precio`. */
    private function comprar(Producto $producto, float $cantidad, float $precio, array $atributos = []): Compra
    {
        $compra = Compra::factory()->create(array_merge(['fecha_emision' => '2026-07-01'], $atributos));

        CompraItem::create([
            'compra_id' => $compra->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => round($cantidad * $precio, 2),
            'subtotal_con_iva' => round($cantidad * $precio, 2),
        ]);

        return $compra;
    }

    public function test_el_cmv_es_el_promedio_ponderado_de_las_compras_por_la_cantidad_vendida(): void
    {
        // 10 a $100 y 30 a $200 → promedio ponderado (1000 + 6000) / 40 = $175.
        $producto = Producto::factory()->create(['costo' => 999]);
        $this->comprar($producto, 10, 100);
        $this->comprar($producto, 30, 200);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 4, 'precio' => 500]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertEqualsWithDelta(700.0, (float) $fila->cmv_total, 0.01);
    }

    public function test_un_producto_sin_compras_registradas_aporta_cmv_cero(): void
    {
        // Es el caso del Id 5 del relevamiento: "Costo Total Actual" > 0 pero CMV = 0.
        $producto = Producto::factory()->create(['costo' => 300]);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $fila->costo_total_actual, 0.01);
    }

    public function test_costo_actual_y_cmv_dan_distinto_sobre_el_mismo_producto(): void
    {
        // El costo de ficha se editó después de la compra: es exactamente el escenario en el que
        // las dos columnas del informe tienen que separarse (FR-013 vs FR-014).
        $producto = Producto::factory()->create(['costo' => 500]);
        $this->comprar($producto, 10, 200);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 900]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertEqualsWithDelta(1500.0, (float) $fila->costo_total_actual, 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $fila->cmv_total, 0.01);
        $this->assertNotEquals((float) $fila->costo_total_actual, (float) $fila->cmv_total);
    }

    public function test_las_compras_eliminadas_no_entran_en_el_promedio(): void
    {
        $producto = Producto::factory()->create(['costo' => 0]);
        $this->comprar($producto, 10, 100);
        $borrada = $this->comprar($producto, 10, 900);
        // Sin eventos: dar de baja una compra dispara la reversión de stock, que exige un
        // depósito activo y no tiene nada que ver con lo que este test verifica.
        Compra::withoutEvents(fn () => $borrada->delete());

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertEqualsWithDelta(100.0, (float) $fila->cmv_total, 0.01);
    }

    public function test_un_item_sin_producto_asociado_no_tiene_costo_ni_cmv(): void
    {
        // Concepto libre: aparece con su descripción, pero no hay ficha de la que sacar costos.
        $this->venta([['producto_id' => null, 'descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 800]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertSame('Flete', $fila->producto);
        $this->assertEqualsWithDelta(0.0, (float) $fila->costo_total_actual, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
    }
}
