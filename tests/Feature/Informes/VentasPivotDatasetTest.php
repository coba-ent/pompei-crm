<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Vendedor;
use App\Services\Informes\VentasInformeQuery;
use App\Services\Informes\VentasPivotDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 069 — dataset que alimenta Rankings y "Arma tu Informe" en Ventas.
 *
 * Es la base de todo cruce, así que se prueba contra los invariantes del data-model. Los datos
 * los arma el propio test con {@see ArmaVentas}, que graba totales coherentes con los ítems: eso
 * importa porque en la base real hay ventas importadas cuyo desglose por línea NO cierra contra
 * su total (ver la bitácora de importación, 16/08/2026). Este test tiene que verificar la
 * FÓRMULA, no la calidad de esos datos.
 */
class VentasPivotDatasetTest extends TestCase
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

    private function dataset(): VentasPivotDataset
    {
        return app(VentasPivotDataset::class);
    }

    private function filas(array $params = []): array
    {
        return $this->dataset()->armar($this->request($params + ['desde' => '2026-08-01', 'hasta' => '2026-08-31']))['filas'];
    }

    private function suma(string $columna, array $params = []): float
    {
        return round(array_sum(array_column($this->filas($params), $columna)), 2);
    }

    public function test_el_total_del_cruce_concilia_con_el_kpi_del_informe(): void
    {
        // Invariante 1: es LA propiedad del dataset. Si el ranking mostrara un total distinto al
        // de las tarjetas de arriba, el informe entero pierde credibilidad.
        $this->venta([
            ['descripcion' => 'A', 'cantidad' => 2, 'precio' => 1000, 'iva_pct' => '21'],
            ['descripcion' => 'B', 'cantidad' => 1, 'precio' => 500, 'iva_pct' => '10.5'],
        ]);
        $this->venta([['descripcion' => 'C', 'cantidad' => 3, 'precio' => 250, 'iva_pct' => '21']]);

        $peticion = $this->request(['desde' => '2026-08-01', 'hasta' => '2026-08-31']);
        $kpis = app(VentasInformeQuery::class)->kpis($peticion);

        $this->assertEqualsWithDelta($kpis['total_ventas'], $this->suma('total_venta'), 0.05);
    }

    public function test_cantidad_de_ventas_cuenta_comprobantes_y_no_lineas(): void
    {
        // Invariante 3: una venta de 3 líneas es UNA venta. Contar filas la contaría tres veces.
        $this->venta([
            ['descripcion' => 'A', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['descripcion' => 'B', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['descripcion' => 'C', 'cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
        ]);

        $filas = $this->filas();

        $this->assertCount(3, $filas, 'el dataset es una fila por ítem');
        $this->assertCount(1, array_unique(array_column($filas, 'comprobante_id')));
    }

    public function test_una_nota_de_credito_resta_y_una_de_debito_suma(): void
    {
        // Invariante 4: sin rama de cálculo aparte — el signo lo pone la proyección.
        $venta = $this->venta([['descripcion' => 'A', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);

        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 100]]);
        $totalConNc = $this->suma('total_venta');

        $this->nota($venta, 'debito', [['cantidad' => 1, 'precio' => 300]]);
        $totalConNcYNd = $this->suma('total_venta');

        $this->assertLessThan(1210.0, $totalConNc, 'la NC tiene que restar');
        $this->assertGreaterThan($totalConNc, $totalConNcYNd, 'la ND tiene que sumar');
    }

    public function test_no_incluye_comprobantes_dados_de_baja(): void
    {
        // Invariante 5.
        $viva = $this->venta([['descripcion' => 'Viva', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);
        $muerta = $this->venta([['descripcion' => 'Muerta', 'cantidad' => 1, 'precio' => 9999, 'iva_pct' => '21']]);
        $muerta->delete();

        $productos = array_column($this->filas(), 'producto');

        $this->assertContains('Viva', $productos);
        $this->assertNotContains('Muerta', $productos);
    }

    public function test_los_registros_sin_dimension_caen_en_su_rotulo_y_no_se_pierden(): void
    {
        // FR-018: agrupar bajo "Sin categoría" es lo correcto; descartarlos haría que el cruce
        // sume menos que el informe, que es justo lo que el invariante 1 prohíbe.
        $this->venta([['descripcion' => 'Huérfana', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']], [
            'categoria_id' => null,
            'vendedor_id' => null,
        ]);

        $fila = collect($this->filas())->firstWhere('producto', 'Huérfana');

        $this->assertSame('Sin categoría', $fila['categoria']);
        $this->assertSame('Sin vendedor', $fila['vendedor']);
        $this->assertSame('Sin tipo de producto', $fila['tipo_producto']);
        $this->assertSame('Sin proveedor', $fila['proveedor']);
    }

    public function test_trae_el_nombre_de_la_categoria_y_del_vendedor_cuando_existen(): void
    {
        $categoria = Categoria::create(['nombre' => 'Mostrador', 'tipo' => 'venta', 'activo' => true]);
        $vendedor = Vendedor::create(['nombre' => 'Lucía']);

        $this->venta([['descripcion' => 'Con datos', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']], [
            'categoria_id' => $categoria->id,
            'vendedor_id' => $vendedor->id,
        ]);

        $fila = collect($this->filas())->firstWhere('producto', 'Con datos');

        $this->assertSame('Mostrador', $fila['categoria']);
        $this->assertSame('Lucía', $fila['vendedor']);
    }

    public function test_una_venta_con_varias_etiquetas_no_duplica_su_fila(): void
    {
        // `etiquetables` es muchos-a-muchos: traerla por JOIN duplicaría la línea y el importe se
        // contaría dos veces. Por eso va por subconsulta.
        $venta = $this->venta([['descripcion' => 'Etiquetada', 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21']]);
        $venta->etiquetas()->attach([
            \App\Models\Etiqueta::create(['nombre' => 'Urgente'])->id,
            \App\Models\Etiqueta::create(['nombre' => 'Mayorista'])->id,
        ]);

        $filas = collect($this->filas())->where('producto', 'Etiquetada');

        $this->assertCount(1, $filas, 'dos etiquetas no pueden duplicar la línea');
        $this->assertStringContainsString('Urgente', $filas->first()['etiquetas']);
        $this->assertStringContainsString('Mayorista', $filas->first()['etiquetas']);
    }

    public function test_el_dataset_declara_sus_dimensiones_y_medidas(): void
    {
        $armado = $this->dataset()->armar($this->request(['desde' => '2026-08-01', 'hasta' => '2026-08-31']));

        $claves = array_column($armado['dimensiones'], 'clave');
        $this->assertContains('clientes', $claves);
        $this->assertContains('vendedores', $claves, 'Ventas sí tiene vendedor');

        $datos = array_column($armado['datos'], 'clave');
        $this->assertContains('total_venta', $datos);
        $this->assertContains('cantidad_ventas', $datos);
        $this->assertNotContains('total_comprobante', $datos, 'se repite por línea: sumarlo contaría de más');
    }

    public function test_el_producto_sin_tipo_ni_proveedor_igual_aparece(): void
    {
        $producto = Producto::factory()->create(['tipo_producto_id' => null, 'proveedor_id' => null]);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 500, 'iva_pct' => '21']]);

        $fila = collect($this->filas())->firstWhere('tipo_producto', 'Sin tipo de producto');

        $this->assertNotNull($fila);
        $this->assertSame('Sin proveedor', $fila['proveedor']);
    }
}
