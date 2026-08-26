<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Services\Import\FuenteFilasImportacion;
use App\Services\Import\ImportadorFilas;
use App\Services\Import\ValidadorFilasImportacion;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 083, US1 — fórmulas de Excel.
 *
 * El caso real: `Ferrum nuevos (2).xlsx` se guardó sin recalcular, así que la caché de valores del
 * archivo traía el TEXTO de las fórmulas. Entraron 124 productos con el código puesto en
 * `=CONCATENAR(...)` y el precio en cero. El volcado ahora pide el valor calculado, y una fórmula
 * que no se puede evaluar bloquea esa fila en vez de guardar el texto.
 */
class FormulasExcelImportacionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
            @unlink(FuenteFilasImportacion::rutaNdjsonPara($ruta));
        }
        $this->temporales = [];

        parent::tearDown();
    }

    /**
     * Planilla guardada **sin precalcular las fórmulas**, que es exactamente el estado en el que
     * llegó el archivo del incidente: el `.xlsx` tiene el `<f>` de la fórmula y ningún valor
     * cacheado que leer.
     *
     * @param  array<int, array<int, mixed>>  $filas
     */
    private function planillaSinPrecalcular(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $hoja->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'formulas').'.xlsx';
        $escritor = new Xlsx($spreadsheet);
        $escritor->setPreCalculateFormulas(false);
        $escritor->save($ruta);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function importador(): ImportadorFilas
    {
        return new ImportadorFilas(app(StockService::class));
    }

    /** FR-011 / SC-004: la fórmula entra por su RESULTADO, tanto en una columna de texto como en una numérica. */
    public function test_una_formula_sin_cachear_entra_por_su_valor_calculado(): void
    {
        $ruta = $this->planillaSinPrecalcular([
            ['Nombre', 'Codigo', 'Costo'],
            ['Producto Uno', '=CONCATENATE("DEP-","44927")', '=100*2'],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre', 1 => 'codigo', 2 => 'costo',
        ], []);

        $this->assertSame([], $resultado['fallidos']);
        $this->assertSame(1, $resultado['importados']);

        $producto = Producto::where('nombre', 'Producto Uno')->firstOrFail();
        $this->assertSame('DEP-44927', $producto->codigo);
        $this->assertEquals(200, $producto->costo);
    }

    /** FR-012 / FR-013: una fórmula rota reporta error de esa fila y NUNCA guarda el texto. */
    public function test_una_formula_rota_reporta_error_y_no_guarda_el_texto(): void
    {
        $ruta = $this->planillaSinPrecalcular([
            ['Nombre', 'Codigo'],
            ['Producto Roto', '=FUNCIONQUENOEXISTE(1)'],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre', 1 => 'codigo',
        ], [], null, ['Nombre', 'Codigo']);

        $this->assertSame(0, $resultado['importados']);
        $this->assertCount(1, $resultado['fallidos']);
        $this->assertStringContainsString('Codigo', $resultado['fallidos'][0]['motivo']);
        $this->assertSame(0, Producto::count());
        $this->assertNull(Producto::where('codigo', 'like', '=%')->first());
    }

    /** FR-013: el texto de una fórmula nunca se persiste, ni siquiera si llega ya como texto crudo. */
    public function test_el_texto_de_una_formula_nunca_se_guarda_como_valor(): void
    {
        $veredicto = (new ValidadorFilasImportacion)->evaluar(
            ['Producto Uno', '=CONCATENAR(A1;B1)'],
            'productos',
            [0 => 'nombre', 1 => 'codigo'],
            [],
            ['Nombre', 'Codigo'],
        );

        $this->assertSame('error', $veredicto['modo']);
        $this->assertArrayNotHasKey('codigo', $veredicto['datos']);
    }

    /** FR-025: un archivo SIN fórmulas se sigue interpretando exactamente igual que antes. */
    public function test_un_archivo_sin_formulas_se_interpreta_igual_que_antes(): void
    {
        $ruta = $this->planillaSinPrecalcular([
            ['Nombre', 'Codigo', 'Costo'],
            ['Producto Uno', 'P-1', 150.5],
            ['Producto Dos', 'P-2', 0],
            ['Producto Tres', 'P-3', null],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre', 1 => 'codigo', 2 => 'costo',
        ], []);

        $this->assertSame([], $resultado['fallidos']);
        $this->assertSame(3, $resultado['importados']);
        $this->assertEquals(150.5, Producto::where('codigo', 'P-1')->firstOrFail()->costo);
        // Un 0 real tiene que entrar como 0, no perderse: es el mismo bug de comparación laxa que
        // ya obligó a `WithStrictNullComparison` en la exportación.
        $this->assertEquals(0, Producto::where('codigo', 'P-2')->firstOrFail()->costo);
    }
}
