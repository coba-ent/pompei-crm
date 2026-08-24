<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Producto;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Informes\ArmaVentas;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * spec 075 — invariantes del CMV con costo congelado (`contracts/cmv-api.md §1.3`).
 *
 * Cada test de acá fija un invariante que, si se rompe, hace que el Informe de Ventas deje de
 * reproducir a Contagram sin fallar ni una sola query. Son la red que impide que el CMV vuelva a
 * ser una derivación del promedio de compras.
 */
class CmvCostoCongeladoTest extends TestCase
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

    /** Compra de `$cantidad` unidades de `$producto` a `$precio`, que alimenta el fallback. */
    private function comprar(Producto $producto, float $cantidad, float $precio): void
    {
        $compra = Compra::factory()->create(['fecha_emision' => '2026-07-01']);

        CompraItem::create([
            'compra_id' => $compra->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => round($cantidad * $precio, 2),
            'subtotal_con_iva' => round($cantidad * $precio, 2),
        ]);
    }

    // -----------------------------------------------------------------------------------
    // I1 — sin costo congelado se usa el promedio de compras (cero regresión histórica)
    // -----------------------------------------------------------------------------------

    public function test_i1_una_linea_sin_costo_congelado_usa_el_promedio_de_compras(): void
    {
        // Es el estado de las ~1M de líneas importadas de Contagram: `costo_unitario` en NULL.
        $producto = Producto::factory()->create(['costo' => 999]);
        $this->comprar($producto, 10, 100);
        $this->comprar($producto, 30, 200);   // promedio ponderado = (1000 + 6000) / 40 = 175

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 4, 'precio' => 500]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertNull($fila->costo_unitario ?? null, 'el helper no debe congelar costo por default');
        $this->assertEqualsWithDelta(700.0, (float) $fila->cmv_total, 0.01);
    }

    // -----------------------------------------------------------------------------------
    // I3 — sin costo congelado ni compras, el KPI da 0 y nunca NULL
    // -----------------------------------------------------------------------------------

    public function test_i3_sin_costo_congelado_ni_compras_el_cmv_es_cero_y_no_null(): void
    {
        $producto = Producto::factory()->create(['costo' => 300]);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertNotNull($fila->cmv_total, 'un KPI de dinero no puede dar NULL');
        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
        // El "Costo Actual" sí sale, porque mira `productos.costo`: son columnas distintas.
        $this->assertEqualsWithDelta(600.0, (float) $fila->costo_total_actual, 0.01);
    }

    // -----------------------------------------------------------------------------------
    // I2 — un costo congelado en 0 gana sobre el promedio de compras
    // -----------------------------------------------------------------------------------

    public function test_i2_un_costo_congelado_en_cero_gana_sobre_el_promedio_de_compras(): void
    {
        // Es el test que detecta el `NULLIF(costo_unitario, 0)` prohibido: el producto TIENE
        // compras registradas, así que si el 0 se tratara como "sin costo" el CMV daría 700.
        $producto = Producto::factory()->create(['costo' => 0]);
        $this->comprar($producto, 10, 100);
        $this->comprar($producto, 30, 200);

        $this->venta([[
            'producto_id' => $producto->id, 'cantidad' => 4, 'precio' => 500, 'costo_unitario' => 0,
        ]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
    }

    // -----------------------------------------------------------------------------------
    // Inmutabilidad (FR-004, SC-002)
    // -----------------------------------------------------------------------------------

    public function test_cambiar_el_costo_del_producto_no_mueve_el_cmv_de_una_venta_ya_hecha(): void
    {
        $producto = Producto::factory()->create(['costo' => 100]);

        $this->venta([[
            'producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 500, 'costo_unitario' => 100,
        ]]);

        $antes = $this->informe()->detalle($this->request())->first();

        $producto->update(['costo' => 900]);

        $despues = $this->informe()->detalle($this->request())->first();

        // El CMV mira hacia atrás y no se movió...
        $this->assertEqualsWithDelta(300.0, (float) $antes->cmv_total, 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $despues->cmv_total, 0.01);
        // ...mientras que "Costo Actual" sí, porque es justamente el costo de hoy (FR-006).
        $this->assertEqualsWithDelta(300.0, (float) $antes->costo_total_actual, 0.01);
        $this->assertEqualsWithDelta(2700.0, (float) $despues->costo_total_actual, 0.01);
    }

    public function test_dos_ventas_del_mismo_producto_separadas_por_un_cambio_de_costo_conservan_cada_una_su_cmv(): void
    {
        // El patrón exacto que se observó en el export de Contagram (US1, escenario 3).
        $producto = Producto::factory()->create(['costo' => 100]);

        $this->venta(
            [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 100]],
            ['fecha_emision' => '2026-08-05']
        );

        $producto->update(['costo' => 400]);

        $this->venta(
            [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 400]],
            ['fecha_emision' => '2026-08-12']
        );

        $filas = $this->informe()->detalle($this->request())->orderBy('fecha')->get();

        $this->assertCount(2, $filas);
        $this->assertEqualsWithDelta(100.0, (float) $filas[0]->cmv_total, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $filas[1]->cmv_total, 0.01);
    }
}
