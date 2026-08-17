<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Producto;
use App\Services\Informes\ComprasInformeQuery;
use App\Services\Informes\ComprasPivotDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 069 — dataset del pivot para el Informe de Compras.
 *
 * Espeja a {@see VentasPivotDatasetTest}. La diferencia que sí importa probar es que Compras
 * **no** ofrece la dimensión "vendedores": su modelo no la tiene, y la spec es explícita en que
 * no se inventan dimensiones para emparejar los dos informes.
 */
class ComprasPivotDatasetTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

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

    /**
     * @param  list<array{producto_id?: int|null, descripcion?: string, cantidad: float, precio: float, iva_pct?: string|null}>  $lineas
     */
    private function compra(array $lineas, array $atributos = []): Compra
    {
        $neto = 0.0;
        $conIva = 0.0;

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;
            $neto += $subtotal;
            $conIva += $subtotal * (1 + $pct / 100);
        }

        $compra = Compra::factory()->create(array_merge([
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => round($neto, 2),
            'subtotal_con_descuento' => round($neto, 2),
            'total' => round($conIva, 2),
        ], $atributos));

        foreach ($lineas as $linea) {
            $subtotal = $linea['cantidad'] * $linea['precio'];
            $pct = is_numeric($linea['iva_pct'] ?? null) ? (float) $linea['iva_pct'] : 0.0;

            CompraItem::create([
                'compra_id' => $compra->id,
                'producto_id' => $linea['producto_id'] ?? null,
                'descripcion' => $linea['descripcion'] ?? 'Ítem',
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $linea['precio'],
                'iva_pct' => $linea['iva_pct'] ?? null,
                'subtotal' => round($subtotal, 2),
                'subtotal_con_iva' => round($subtotal * (1 + $pct / 100), 2),
            ]);
        }

        return $compra;
    }

    private function request(array $params = []): Request
    {
        return Request::create('/informes/compras', 'GET', $params + ['desde' => '2026-08-01', 'hasta' => '2026-08-31']);
    }

    private function armado(): array
    {
        return app(ComprasPivotDataset::class)->armar($this->request());
    }

    private function filas(): array
    {
        return $this->armado()['filas'];
    }

    public function test_el_total_del_cruce_concilia_con_el_kpi_del_informe(): void
    {
        // Invariante 2.
        $this->compra([
            ['descripcion' => 'A', 'cantidad' => 2, 'precio' => 1000, 'iva_pct' => '21'],
            ['descripcion' => 'B', 'cantidad' => 1, 'precio' => 500, 'iva_pct' => '10.5'],
        ]);
        $this->compra([['descripcion' => 'C', 'cantidad' => 3, 'precio' => 250, 'iva_pct' => '21']]);

        $kpis = app(ComprasInformeQuery::class)->kpis($this->request());

        // La columna se pide al catálogo en vez de escribirla a mano: así el test sigue probando
        // "la medida Total Compra concilia" y no "la columna X concilia", que es lo que importa.
        $columna = app(\App\Services\Informes\DimensionesPivot::class)->medidas('compras')['total_compra']['columna'];
        $suma = round(array_sum(array_column($this->filas(), $columna)), 2);

        $this->assertEqualsWithDelta($kpis['total_compras'], $suma, 0.05);
    }

    public function test_cantidad_de_compras_cuenta_comprobantes_y_no_lineas(): void
    {
        $this->compra([
            ['descripcion' => 'A', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['descripcion' => 'B', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
        ]);

        $filas = $this->filas();

        $this->assertCount(2, $filas);
        $this->assertCount(1, array_unique(array_column($filas, 'comprobante_id')));
    }

    public function test_no_incluye_compras_dadas_de_baja(): void
    {
        $this->compra([['descripcion' => 'Viva', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);
        $muerta = $this->compra([['descripcion' => 'Muerta', 'cantidad' => 1, 'precio' => 9999, 'iva_pct' => '21']]);
        $muerta->delete();

        $productos = array_column($this->filas(), 'producto_servicio');

        $this->assertContains('Viva', $productos);
        $this->assertNotContains('Muerta', $productos);
    }

    public function test_compras_no_ofrece_la_dimension_vendedores(): void
    {
        // El modelo de Compras no tiene vendedor. Ofrecer la dimensión daría una columna vacía
        // que sólo confunde (research R9).
        $claves = array_column($this->armado()['dimensiones'], 'clave');

        $this->assertContains('proveedores', $claves);
        $this->assertNotContains('vendedores', $claves);
    }

    public function test_la_compra_sin_categoria_cae_en_su_rotulo(): void
    {
        $this->compra([['descripcion' => 'Sin cat', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']], [
            'categoria_id' => null,
        ]);

        $fila = collect($this->filas())->firstWhere('producto_servicio', 'Sin cat');

        $this->assertSame('Sin categoría', $fila['categoria']);
    }

    public function test_trae_la_categoria_raiz_cuando_la_compra_tiene_subcategoria(): void
    {
        // El cruce agrupa por la categoría RAÍZ, no por la hoja: es como lo muestra Contagram.
        $padre = Categoria::create(['nombre' => 'Mercadería', 'tipo' => 'compra', 'activo' => true]);
        $hija = Categoria::create(['nombre' => 'Griferías', 'tipo' => 'compra', 'activo' => true, 'categoria_padre_id' => $padre->id]);

        $this->compra([['descripcion' => 'Con subcat', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21']], [
            'categoria_id' => $hija->id,
        ]);

        $fila = collect($this->filas())->firstWhere('producto_servicio', 'Con subcat');

        $this->assertSame('Mercadería', $fila['categoria']);
    }

    public function test_el_producto_sin_tipo_igual_aparece(): void
    {
        $producto = Producto::factory()->create(['tipo_producto_id' => null]);

        $this->compra([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'iva_pct' => '21']]);

        $this->assertNotEmpty($this->filas());
    }
}
