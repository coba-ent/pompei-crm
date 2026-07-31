<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\ImportadorVinculaciones;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 021 (reemplazo), US2: importación de vinculaciones de Tiendanube desde
 * el export nativo de productos. FR-009..FR-018, research.md R4/R5.
 */
class TiendanubeImportadorVinculacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    private function archivo(array $filas): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $fila => $valores) {
            foreach (array_values($valores) as $columna => $valor) {
                $sheet->setCellValueByColumnAndRow($columna + 1, $fila + 1, $valor);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'tn_import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * OJO: `Http::fake()` ACUMULA stubs (no los reemplaza) y gana el primero
     * registrado que matchea — llamarla dos veces en el mismo test para
     * "resetear" el catálogo hace que la segunda corrida siga golpeando la
     * primera secuencia (ya agotada). Por eso `$vecesCargado` repite el mismo
     * catálogo tantas veces como corridas de `importar()` haga el test.
     *
     * @param  array<int, array{id: int, slug: string, variantId: int}>  $productos
     */
    private function fakearCatalogo(array $productos, int $totalPaginas = 1, int $vecesCargado = 1): void
    {
        $paginas = [];
        foreach (array_chunk($productos, (int) ceil(count($productos) / max($totalPaginas, 1)) ?: 1) as $chunk) {
            $paginas[] = [
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => [
                    'pagination' => ['total_pages' => $totalPaginas, 'total_elements' => count($productos)],
                    'products' => array_map(fn ($p) => [
                        'id' => $p['id'],
                        'product_url' => 'https://tienda.example.com/productos/'.$p['slug'],
                        'variants' => [['id' => $p['variantId']]],
                    ], $chunk),
                ]],
            ];
        }

        $secuencia = Http::sequence();
        for ($i = 0; $i < $vecesCargado; $i++) {
            foreach ($paginas as $pagina) {
                $secuencia->push($pagina, 200);
            }
        }

        Http::fake(['admin-mcp.tiendanube.com/' => $secuencia]);
    }

    public function test_match_exacto_de_codigo_crea_la_vinculacion(): void
    {
        $producto = Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(0, $resumen['fallidas']);
        $this->assertDatabaseHas('tn_variante_producto', [
            'producto_id' => $producto->id, 'variant_id' => 500, 'tn_product_id' => '100',
        ]);
    }

    public function test_match_por_numero_inicial_del_codigo(): void
    {
        $producto = Producto::factory()->create(['codigo' => '27205 AL605028 BL']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['producto_id' => $producto->id, 'variant_id' => 500]);
    }

    public function test_identificador_de_url_no_encontrado_en_el_catalogo_en_vivo(): void
    {
        Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'otro-producto', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-despublicado'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('tiendanube_no_encontrado', $resumen['detalle_fallidas'][0]['motivo']);
    }

    public function test_sku_sin_match_ni_exacto_ni_parcial(): void
    {
        Producto::factory()->create(['codigo' => '99999']);
        $this->fakearCatalogo([]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('producto_no_encontrado', $resumen['detalle_fallidas'][0]['motivo']);
    }

    public function test_fila_ya_vinculada_por_sku_y_por_producto(): void
    {
        $productoA = Producto::factory()->create(['codigo' => '27205']);
        $productoB = Producto::factory()->create(['codigo' => '30000']);

        TiendanubeVarianteProducto::create(['variant_id' => 500, 'tn_product_id' => '100', 'producto_id' => $productoA->id]);
        TiendanubeVarianteProducto::create(['variant_id' => 900, 'tn_product_id' => '200', 'producto_id' => $productoB->id]);

        $this->fakearCatalogo([
            ['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500], // variant_id ya vinculado
            ['id' => 300, 'slug' => 'producto-30000', 'variantId' => 999], // producto_id ya vinculado (productoB)
        ]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
            ['30000', 'producto-30000'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(2, $resumen['fallidas']);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertSame('sku', $resumen['detalle_fallidas'][0]['detalle']);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][1]['motivo']);
        $this->assertSame('producto', $resumen['detalle_fallidas'][1]['detalle']);
    }

    public function test_una_fila_fallida_no_interrumpe_el_procesamiento_de_las_siguientes(): void
    {
        Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['99999', 'no-existe'],
            ['27205', 'producto-27205'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(2, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
    }

    public function test_reintentar_la_misma_importacion_no_sobrescribe_lo_ya_vinculado(): void
    {
        Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]], totalPaginas: 1, vecesCargado: 2);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
        ]);

        app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertDatabaseCount('tn_variante_producto', 1);
    }

    public function test_filas_completamente_vacias_se_ignoran_sin_contar_como_fallidas(): void
    {
        Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['', ''],
            ['27205', 'producto-27205'],
        ]);

        $resumen = app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(0, $resumen['fallidas']);
    }

    public function test_el_vinculo_creado_queda_con_stock_y_precio_pendiente_en_su_default(): void
    {
        Producto::factory()->create(['codigo' => '27205']);
        $this->fakearCatalogo([['id' => 100, 'slug' => 'producto-27205', 'variantId' => 500]]);

        $archivo = $this->archivo([
            ['SKU', 'Identificador de URL'],
            ['27205', 'producto-27205'],
        ]);

        app(ImportadorVinculaciones::class)->importar($archivo->getRealPath(), auth()->user());

        $vinculo = TiendanubeVarianteProducto::where('variant_id', 500)->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertFalse($vinculo->precio_pendiente);
    }
}
