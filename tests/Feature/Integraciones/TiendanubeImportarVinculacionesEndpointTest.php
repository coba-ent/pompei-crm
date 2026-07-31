<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 021 (reemplazo), US2: endpoint `POST .../vinculaciones/importar`.
 * FR-015 (rechazo temprano) y contracts/rutas-internas.md.
 */
class TiendanubeImportarVinculacionesEndpointTest extends TestCase
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

    private function archivoXlsx(array $filas, string $nombre = 'productos.xlsx'): UploadedFile
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

        return new UploadedFile($path, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Export real de Tiendanube: separador `;`, codificación ISO-8859-1 (research.md R6). */
    private function archivoCsvReal(array $filas): UploadedFile
    {
        $lineas = array_map(fn ($fila) => implode(';', $fila), $filas);
        $contenido = mb_convert_encoding(implode("\r\n", $lineas), 'ISO-8859-1', 'UTF-8');

        $path = tempnam(sys_get_temp_dir(), 'tn_import').'.csv';
        file_put_contents($path, $contenido);

        return new UploadedFile($path, 'productos.csv', 'text/csv', null, true);
    }

    public function test_rechaza_archivo_vacio_antes_de_procesar_ninguna_fila(): void
    {
        $archivo = UploadedFile::fake()->create('vacio.csv', 0, 'text/csv');
        file_put_contents($archivo->getRealPath(), '');

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.importar'), ['archivo' => $archivo]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_rechaza_extension_no_soportada(): void
    {
        $archivo = UploadedFile::fake()->create('productos.pdf', 50, 'application/pdf');

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.importar'), ['archivo' => $archivo]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_rechaza_archivo_sin_las_columnas_reconocibles(): void
    {
        $archivo = $this->archivoXlsx([
            ['Nombre', 'Precio'],
            ['Producto A', '100'],
        ]);

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.importar'), ['archivo' => $archivo]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_archivo_real_separador_punto_y_coma_iso_8859_1_devuelve_el_resumen(): void
    {
        Producto::factory()->create(['codigo' => '27205']);

        Http::fake(['admin-mcp.tiendanube.com/' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['isError' => false, 'structuredContent' => [
                'pagination' => ['total_pages' => 1, 'total_elements' => 1],
                'products' => [[
                    'id' => 100,
                    'product_url' => 'https://tienda.example.com/productos/producto-27205',
                    'variants' => [['id' => 500]],
                ]],
            ]],
        ], 200)]);

        // Encabezados y filas con las mismas 25 columnas reales (research.md R6) no son
        // necesarias para el test — sólo importan "Identificador de URL" (col 1) y "SKU" (col 11).
        $encabezados = ['Identificador de URL', 'Nombre', 'Categorías', 'Precio', 'Precio promocional',
            'Peso (kg)', 'Alto (cm)', 'Ancho (cm)', 'Profundidad (cm)', 'Variante', 'SKU', 'Código de barras',
            'Stock', 'Mostrar en tienda', 'Descripción', 'Tags', 'Marca', 'Imágenes', 'Sexo', 'Edad',
            'Título para SEO', 'Descripción para SEO', 'Peso (para Correo Argentino)', 'MPN', 'Días de preparación'];
        $fila = array_fill(0, 25, '');
        $fila[0] = 'producto-27205';
        $fila[10] = '27205';

        $archivo = $this->archivoCsvReal([$encabezados, $fila]);

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.importar'), ['archivo' => $archivo]);

        $respuesta->assertOk()->assertJsonPath('ok', true)->assertJsonPath('vinculadas', 1)->assertJsonPath('fallidas', 0);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 500, 'tn_product_id' => '100']);
    }

    public function test_el_alta_manual_existente_sigue_funcionando_sin_cambios(): void
    {
        $producto = Producto::factory()->create();

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.store'), [
            'variant_id' => 12345,
            'tn_product_id' => '999',
            'producto_id' => $producto->id,
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 12345, 'producto_id' => $producto->id]);
    }
}
