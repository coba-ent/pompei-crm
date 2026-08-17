<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `migracion:corregir-bonificacion` — baja a la línea la bonificación que la importación dejó
 * colgada de la cabecera.
 *
 * El defecto: Contagram bonifica por renglón, y el importador trataba un "Subtotal con Descuento"
 * **en cero** como celda vacía, cayendo al fallback `cantidad × precio`. En una línea bonificada al
 * 100% ese cero es el dato. Resultado: 27 ventas con el neto de línea inflado en $1.733.682 y el
 * total de cabecera correcto (16/08/2026, ver la bitácora de importación).
 *
 * Lo que fija este test es el límite del arreglo: corrige el desglose y **no toca la plata**. Si
 * alguna vez esta corrección empezara a mover `ventas.total` o los cobros, el error dejaría de ser
 * cosmético y pasaría a descuadrar las cajas.
 */
class BonificacionPorLineaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `--imports` apunta a una carpeta vacía a propósito: sin eso el comando levantaría los Excel
     * reales de `public/imports`, que pesan cientos de MB y no son un fixture de test. Acá se
     * ejercita el camino sin archivo de origen, que es el que decide por la regla del 100%.
     */
    private function corregir(bool $aplicar = true)
    {
        return $this->artisan('migracion:corregir-bonificacion', [
            '--aplicar' => $aplicar,
            '--imports' => storage_path('framework/testing/sin-imports'),
        ]);
    }

    /**
     * Venta con el defecto: la bonificación quedó en `ventas.descuento` y los subtotales de línea
     * siguen a precio de lista, así que el desglose no cierra contra el total.
     */
    private function ventaConElDefecto(float $neto, float $total = 0.0): Venta
    {
        $venta = Venta::create([
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Local', 'activo' => true])->id,
            'fecha_emision' => '2024-11-05',
            'tipo_comprobante' => 'B',
            'legacy_id' => '2024-FC-15253',
            'subtotal_sin_descuento' => $neto,
            'descuento' => $neto,
            'subtotal_con_descuento' => $neto,
            'total' => $total,
        ]);

        VentaItem::create([
            'venta_id' => $venta->id,
            'descripcion' => 'Mueble sobre inodoro',
            'cantidad' => 1,
            'precio_unitario' => $neto,
            'iva_pct' => '21',
            'subtotal' => $neto,
            'subtotal_con_iva' => $neto,
        ]);

        return $venta;
    }

    public function test_una_venta_bonificada_al_100_deja_sus_lineas_en_cero(): void
    {
        // Sin archivo de origen: manda la regla del 100%, que es la única lectura posible cuando el
        // total es 0 y el descuento de cabecera es igual al neto de las líneas.
        $venta = $this->ventaConElDefecto(398000.00);

        $this->corregir()
            ->assertSuccessful();

        $item = VentaItem::where('venta_id', $venta->id)->first();

        $this->assertEquals(0.0, (float) $item->subtotal);
        $this->assertEquals(0.0, (float) $item->subtotal_con_iva);
        $this->assertEquals(100.0, (float) $item->descuento_pct);
    }

    public function test_la_cabecera_queda_consistente_con_las_lineas(): void
    {
        // `subtotal_sin_descuento = subtotal_con_descuento` con descuento > 0 es justamente el
        // síntoma: en pantalla se ve "Descuento General (0%)" con un importe distinto de cero.
        $venta = $this->ventaConElDefecto(398000.00);

        $this->corregir();

        $venta->refresh();

        $this->assertEquals(398000.00, (float) $venta->subtotal_sin_descuento);
        $this->assertEquals(0.0, (float) $venta->subtotal_con_descuento);
        $this->assertEquals(398000.00, (float) $venta->descuento);
    }

    public function test_no_toca_el_total_de_la_venta(): void
    {
        // El invariante que hace segura la corrección: la plata cobrada y la contabilizada salen de
        // `ventas.total`, no del desglose por línea.
        $venta = $this->ventaConElDefecto(398000.00);
        $totalAntes = (float) $venta->total;

        $this->corregir();

        $this->assertEquals($totalAntes, (float) $venta->refresh()->total);
    }

    public function test_no_toca_una_venta_sin_el_defecto(): void
    {
        $sana = Venta::create([
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id,
            'fecha_emision' => '2024-11-05',
            'tipo_comprobante' => 'B',
            'subtotal_sin_descuento' => 1000.00,
            'descuento' => 100.00,
            'subtotal_con_descuento' => 900.00,
            'total' => 1089.00,
        ]);
        $item = VentaItem::create([
            'venta_id' => $sana->id,
            'descripcion' => 'Con descuento ya aplicado',
            'cantidad' => 1,
            'precio_unitario' => 1000.00,
            'descuento_pct' => 10,
            'iva_pct' => '21',
            'subtotal' => 900.00,
            'subtotal_con_iva' => 1089.00,
        ]);

        $this->corregir();

        $this->assertEquals(900.00, (float) $item->refresh()->subtotal);
        $this->assertEquals(900.00, (float) $sana->refresh()->subtotal_con_descuento);
    }

    public function test_es_idempotente(): void
    {
        $venta = $this->ventaConElDefecto(398000.00);

        $this->corregir();
        $this->corregir()
            ->expectsOutputToContain('Nada que hacer')
            ->assertSuccessful();

        $this->assertEquals(0.0, (float) VentaItem::where('venta_id', $venta->id)->value('subtotal'));
    }

    public function test_el_dry_run_no_escribe(): void
    {
        $venta = $this->ventaConElDefecto(398000.00);

        $this->corregir(aplicar: false)->assertSuccessful();

        $this->assertEquals(398000.00, (float) VentaItem::where('venta_id', $venta->id)->value('subtotal'));
    }

    public function test_no_toca_una_venta_con_total_distinto_de_cero_sin_archivo_de_origen(): void
    {
        // Descuento parcial: repartirlo adivinando sobre qué renglones cayó sería inventar datos.
        // Sin el export por ítem que lo diga, la venta se deja como está y se reporta.
        $venta = $this->ventaConElDefecto(1850082.59, total: 1902809.97);

        $this->corregir()
            ->expectsOutputToContain('NO SE TOCA');

        $this->assertEquals(1850082.59, (float) VentaItem::where('venta_id', $venta->id)->value('subtotal'));
    }

    public function test_no_crea_movimientos_de_stock(): void
    {
        // La corrección cambia importes, no unidades. Se escribe con `DB::table` justamente para no
        // despertar los observers de venta, que sí mueven stock.
        $this->ventaConElDefecto(398000.00);
        $antes = DB::table('movimientos_stock')->count();

        $this->corregir();

        $this->assertSame($antes, DB::table('movimientos_stock')->count());
    }
}
