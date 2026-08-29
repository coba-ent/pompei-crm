<?php

namespace Tests\Feature;

use App\Models\ImportacionCorrida;
use App\Models\Producto;
use App\Services\Import\ArchivoImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 093, FR-016 — conservar el archivo NO puede hacer fallar una importación.
 *
 * `ArchivoImportacionDescargaTest` prueba el servicio **aislado**. Esto es lo otro: el flujo real
 * del asistente (subir → prevalidar → confirmar por tandas) con el guardado del archivo rompiendo
 * adentro. Es la garantía que importa — que un disco lleno no impida actualizar precios — y aislar
 * el servicio no la demuestra, porque el riesgo está en cómo lo llama el controlador.
 */
class ArchivoImportacionNoRompeImportacionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        $this->temporales = [];

        parent::tearDown();
    }

    /** @param  array<int, array<int, mixed>>  $filas */
    private function archivoSubido(array $filas, string $nombre = 'clientes.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $sheet->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'fr016').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @param  array<int|string, string>  $mapeo */
    private function prevalidar(string $entidad, array $mapeo): void
    {
        $offset = 0;

        do {
            $cuerpo = $this->post(route('importacion.prevalidar', $entidad), [
                'mapeo' => $mapeo, 'offset' => $offset,
            ])->assertOk()->json();

            $offset = $cuerpo['procesadas'];
        } while (! $cuerpo['terminado']);
    }

    /**
     * Recorre el asistente entero como lo hace el navegador.
     *
     * @param  array<int|string, string>  $mapeo
     */
    private function correrTandas(string $entidad, array $mapeo): array
    {
        $this->prevalidar($entidad, $mapeo);

        $offset = 0;
        $vueltas = 0;

        do {
            $ultima = $this->post(route('importacion.confirmar-lote', $entidad), [
                'mapeo' => $mapeo, 'offset' => $offset,
            ])->assertOk()->json();

            $offset = $ultima['procesadas'];
            $this->assertLessThan(50, ++$vueltas, 'El loop de tandas no termina.');
        } while (! $ultima['terminado']);

        return $ultima;
    }

    /**
     * El caso que importa: el guardado del archivo explota (disco lleno, permisos, lo que sea) y
     * la importación tiene que terminar igual, con sus registros escritos.
     */
    public function test_si_el_guardado_del_archivo_explota_la_importacion_termina_igual(): void
    {
        Storage::fake('local');

        // Un servicio que falla del peor modo posible: lanzando. Si el controlador no lo contuviera,
        // la importación entera se caería acá.
        $this->app->bind(ArchivoImportacionService::class, function () {
            return new class extends ArchivoImportacionService
            {
                public function conservar(ImportacionCorrida $corrida, string $rutaRelativaTemporal): bool
                {
                    throw new \RuntimeException('No space left on device');
                }
            };
        });

        // ⚠️ Tiene que ser **productos**: es la única entidad que crea `ImportacionCorrida`
        // (spec 078), y sin corrida el guardado del archivo ni se intenta. Con `clientes` este
        // test pasaría sin ejercitar nada.
        $this->post(route('importacion.subir', 'productos'), [
            'archivo' => $this->archivoSubido([
                ['Nombre', 'Codigo'],
                ['Producto Uno', 'FR016-A'],
                ['Producto Dos', 'FR016-B'],
            ], 'productos.xlsx'),
        ]);

        $resultado = $this->correrTandas('productos', [0 => 'nombre', 1 => 'codigo']);

        // La importación terminó y los productos existen: es la garantía de FR-016.
        $this->assertTrue($resultado['terminado']);
        $this->assertSame(2, Producto::count());
        $this->assertNotNull(Producto::where('codigo', 'FR016-A')->first());
        $this->assertNotNull(Producto::where('codigo', 'FR016-B')->first());

        // Y la corrida quedó registrada, sin archivo pero completa.
        $corrida = ImportacionCorrida::where('entidad', 'productos')->latest('id')->first();
        $this->assertNotNull($corrida);
        $this->assertNull($corrida->archivo_guardado_ruta);
    }

    /**
     * Mismo escenario pero por el camino habitual del fallo (el temporal ya no está, sin
     * excepción): la corrida queda registrada como "nunca se guardó", no como "vencido".
     */
    public function test_un_guardado_fallido_deja_la_corrida_sin_archivo_pero_completa(): void
    {
        Storage::fake('local');

        $this->app->bind(ArchivoImportacionService::class, function () {
            return new class extends ArchivoImportacionService
            {
                public function conservar(ImportacionCorrida $corrida, string $rutaRelativaTemporal): bool
                {
                    return false; // falló y lo registró, sin lanzar
                }
            };
        });

        $this->post(route('importacion.subir', 'productos'), [
            'archivo' => $this->archivoSubido([
                ['Nombre', 'Codigo'],
                ['Producto Uno', 'FR016-1'],
            ], 'productos.xlsx'),
        ]);

        $resultado = $this->correrTandas('productos', [0 => 'nombre', 1 => 'codigo']);

        $this->assertTrue($resultado['terminado']);

        $corrida = ImportacionCorrida::where('entidad', 'productos')->latest('id')->first();
        $this->assertNotNull($corrida, 'La corrida tiene que haberse registrado igual.');
        $this->assertNull($corrida->archivo_guardado_ruta);
        // "Nunca se guardó" y NO "vencido": no se puede confundir un fallo con una purga (FR-015).
        $this->assertSame('nunca_guardado', $corrida->estadoArchivo());
    }

    /** El camino feliz del mismo flujo real: la copia queda asociada y se puede descargar. */
    public function test_en_una_importacion_normal_el_archivo_queda_conservado(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'productos'), [
            'archivo' => $this->archivoSubido([
                ['Nombre', 'Codigo'],
                ['Producto Uno', 'FR012-1'],
            ], 'lista_de_precios.xlsx'),
        ]);

        $this->correrTandas('productos', [0 => 'nombre', 1 => 'codigo']);

        $corrida = ImportacionCorrida::where('entidad', 'productos')->latest('id')->first();

        $this->assertNotNull($corrida->archivo_guardado_ruta);
        $this->assertSame('disponible', $corrida->estadoArchivo());
        $this->assertSame('lista_de_precios.xlsx', $corrida->archivo_original);
        // La copia sobrevive a la limpieza de los temporales de la importación.
        $this->assertTrue(Storage::disk('local')->exists($corrida->archivo_guardado_ruta));
    }
}
