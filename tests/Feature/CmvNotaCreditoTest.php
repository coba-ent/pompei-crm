<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\NotaCreditoDebitoItem;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Informes\ArmaVentas;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * spec 075 FR-008 — costo congelado en las notas de crédito y débito.
 *
 * Lo que se prueba acá es una sola cosa, dicha de tres maneras: **anular una venta tiene que dejar
 * el Resultado en cero**. Si la nota tomara un costo distinto al de la venta que revierte, anular
 * dejaría un residuo en el KPI que nadie sabría de dónde salió.
 */
class CmvNotaCreditoTest extends TestCase
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

    private function resultadoTotal(): float
    {
        return (float) $this->informe()->detalle($this->request())->sum('resultado');
    }

    // -----------------------------------------------------------------------------------

    public function test_una_nc_total_sobre_una_venta_con_costo_congelado_deja_el_resultado_en_cero(): void
    {
        $producto = Producto::factory()->create(['costo' => 120]);

        $venta = $this->venta([[
            'producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 500, 'costo_unitario' => 120,
        ]]);

        $this->nota($venta, 'credito', [[
            'producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 500, 'costo_unitario' => 120,
        ]]);

        $this->assertEqualsWithDelta(0.0, $this->resultadoTotal(), 0.01);
    }

    public function test_el_costo_de_la_nota_se_guarda_en_positivo_y_el_signo_lo_pone_la_cantidad(): void
    {
        // Invariante I5: si el costo se guardara en negativo, la NC SUMARÍA CMV en vez de restarlo.
        $producto = Producto::factory()->create(['costo' => 120]);

        $venta = $this->venta([[
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 120,
        ]]);
        $this->nota($venta, 'credito', [[
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 120,
        ]]);

        $this->assertSame(120.0, (float) NotaCreditoDebitoItem::first()->costo_unitario);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');
        $this->assertEqualsWithDelta(120.0, (float) $filas['venta']->cmv_total, 0.01);
        $this->assertEqualsWithDelta(-120.0, (float) $filas['nc']->cmv_total, 0.01);
    }

    public function test_una_linea_con_origen_nuevo_usa_su_propio_costo_congelado(): void
    {
        // Un ajuste que no revierte nada de la venta original congela el costo vigente al emitir
        // la nota, no el de la venta (`data-model.md §2`).
        $producto = Producto::factory()->create(['costo' => 90]);

        $venta = $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 120]]);
        $this->nota($venta, 'debito', [[
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100, 'costo_unitario' => 90, 'origen' => 'nuevo',
        ]]);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertEqualsWithDelta(90.0, (float) $filas['nd']->cmv_total, 0.01);
    }

    public function test_una_nota_sin_venta_asociada_no_rompe_el_informe(): void
    {
        $producto = Producto::factory()->create(['costo' => 90]);

        $this->nota(null, 'credito', [[
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100, 'costo_unitario' => 90, 'origen' => 'nuevo',
        ]]);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertSame('nc', $fila->tipo_operacion);
        $this->assertEqualsWithDelta(-90.0, (float) $fila->cmv_total, 0.01);
    }

    public function test_una_nc_sobre_una_venta_historica_cae_al_mismo_fallback_que_la_venta(): void
    {
        // Ninguna de las dos tiene costo congelado, así que las dos toman el promedio de compras y
        // el neto sigue dando cero. Es el caso de anular hoy una venta importada de Contagram.
        $producto = Producto::factory()->create(['costo' => 999]);
        $this->comprar($producto, 10, 100);
        $this->comprar($producto, 30, 200);   // promedio = 175

        $venta = $this->venta([['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500]]);
        $this->nota($venta, 'credito', [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500]]);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertEqualsWithDelta(350.0, (float) $filas['venta']->cmv_total, 0.01);
        $this->assertEqualsWithDelta(-350.0, (float) $filas['nc']->cmv_total, 0.01);
        $this->assertEqualsWithDelta(0.0, $this->resultadoTotal(), 0.01);
    }

    public function test_una_nota_migrada_sin_detalle_sigue_aportando_cmv_cero_y_no_null(): void
    {
        // El LEFT JOIN no trae fila de ítem: `costo_unitario` es NULL, el promedio también, y el
        // COALESCE tiene que cerrar en 0 sin envenenar el SUM del KPI.
        $this->nota(null, 'credito', []);

        $fila = $this->informe()->detalle($this->request())->first();

        $this->assertNotNull($fila->cmv_total);
        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
    }

    // -----------------------------------------------------------------------------------
    // El endpoint real: que el controlador resuelva el costo, no sólo el helper de tests
    // -----------------------------------------------------------------------------------

    public function test_el_endpoint_copia_el_costo_congelado_de_la_venta_original(): void
    {
        Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->syncWithoutDetaching(Rol::where('nombre', 'Admin')->value('id'));

        $producto = Producto::factory()->create(['costo' => 120]);
        $venta = $this->venta([[
            'producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500, 'costo_unitario' => 120,
        ]]);

        // El costo de la ficha cambia DESPUÉS de la venta: la nota tiene que revertir el costo de
        // la venta (120), no el de hoy (900).
        $producto->update(['costo' => 900]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Anulación total',
            'mes_imputacion' => '2026-08-12',
            'fecha_emision' => '2026-08-12',
            'monto' => 1000,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500, 'iva_pct' => '0'],
            ],
        ])->assertCreated();

        $this->assertSame(120.0, (float) NotaCreditoDebitoItem::firstOrFail()->costo_unitario);
    }

    public function test_una_linea_de_nota_que_agrupa_dos_lineas_de_venta_promedia_sus_costos(): void
    {
        // Hallazgo de la prueba en navegador (24/08/2026): el formulario de NC/ND **agrupa por
        // producto** los ítems de la venta original, así que una línea de nota con cantidad 2
        // puede estar revirtiendo dos líneas de venta con costos congelados distintos. Con una
        // cola de costos sueltos la NC revertía 2 × el primer costo y anular la venta dejaba
        // $65.679,38 de residuo en el Resultado.
        Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->syncWithoutDetaching(Rol::where('nombre', 'Admin')->value('id'));

        $producto = Producto::factory()->create(['costo' => 400]);

        // Dos líneas del mismo producto con costos congelados distintos: 100 y 300.
        $venta = $this->venta([
            ['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 100],
            ['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'costo_unitario' => 300],
        ]);

        // La nota las revierte agrupadas en UNA línea de cantidad 2.
        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Anulación total agrupada',
            'mes_imputacion' => '2026-08-12',
            'fecha_emision' => '2026-08-12',
            'monto' => 1000,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500, 'iva_pct' => '0'],
            ],
        ])->assertCreated();

        // Promedio ponderado de lo consumido: (100 + 300) / 2 = 200.
        $this->assertSame(200.0, (float) NotaCreditoDebitoItem::firstOrFail()->costo_unitario);

        // Y lo que importa: el CMV revertido iguala al aportado, y el neto cierra en cero.
        $filas = $this->informe()->detalle($this->request())->get();
        $this->assertEqualsWithDelta(0.0, (float) $filas->sum('cmv_total'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->resultadoTotal(), 0.01);
    }
}
