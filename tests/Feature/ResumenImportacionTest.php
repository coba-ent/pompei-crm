<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 083, US4 — el resumen tiene que hablar de ESTA importación.
 *
 * El caso reproducido: un acumulado que había quedado en sesión de una importación abandonada se
 * sumaba a la siguiente, y el resumen informaba 1002 registros habiendo importado 2. El número no
 * era sólo feo: es el número con el que el usuario decide si la importación salió bien.
 */
class ResumenImportacionTest extends TestCase
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
    private function subir(string $entidad, array $filas): void
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $hoja->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'resumen').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->temporales[] = $ruta;

        $archivo = new UploadedFile(
            $ruta,
            'planilla.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $this->post(route('importacion.subir', $entidad), ['archivo' => $archivo])->assertRedirect();
    }

    /** @param  array<int|string, string>  $mapeo */
    private function importar(string $entidad, array $mapeo): void
    {
        $offset = 0;
        do {
            $cuerpo = $this->post(route('importacion.prevalidar', $entidad), ['mapeo' => $mapeo, 'offset' => $offset])
                ->assertOk()->json();
            $offset = $cuerpo['procesadas'];
        } while (! $cuerpo['terminado']);

        $offset = 0;
        do {
            $cuerpo = $this->post(route('importacion.confirmar-lote', $entidad), ['mapeo' => $mapeo, 'offset' => $offset])
                ->assertOk()->json();
            $offset = $cuerpo['procesadas'];
        } while (! $cuerpo['terminado']);
    }

    /**
     * **El caso reproducido**, tal cual pasó: 1000 registros residuales en el acumulado de sesión
     * más 2 importados de verdad informaban 1002.
     *
     * FR-021 / SC-007.
     */
    public function test_un_acumulado_residual_no_contamina_el_resumen_de_la_importacion_nueva(): void
    {
        Storage::fake('local');

        // Restos de una importación anterior que quedó a mitad de camino.
        session(['importacion_resultado_parcial' => [
            'importados' => 1000,
            'fallidos' => [],
            'advertencias' => [],
            'corrida_ref' => 'una-corrida-vieja',
        ]]);

        $this->subir('clientes', [
            ['Nombre'],
            ['Cliente Uno'],
            ['Cliente Dos'],
        ]);

        $this->importar('clientes', [0 => 'nombre']);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertOk();

        $this->assertSame(2, $resumen->viewData('resultado')['importados']);
        $this->assertSame(2, Cliente::count());
    }

    /** FR-022: abandonar una importación a mitad y arrancar otra no arrastra nada. */
    public function test_abandonar_una_importacion_y_arrancar_otra_no_arrastra_nada(): void
    {
        Storage::fake('local');

        // Primera importación: se sube, se prevalida y se abandona sin confirmar.
        $this->subir('clientes', [
            ['Nombre'],
            ['Abandonado Uno'],
            ['Abandonado Dos'],
        ]);
        $this->post(route('importacion.prevalidar', 'clientes'), ['mapeo' => [0 => 'nombre'], 'offset' => 0])->assertOk();

        // Segunda importación, desde cero.
        $this->subir('clientes', [
            ['Nombre'],
            ['Cliente Real'],
        ]);
        $this->importar('clientes', [0 => 'nombre']);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));

        $this->assertSame(1, $resumen->viewData('resultado')['importados']);
        $this->assertSame(1, Cliente::count());
        $this->assertNull(Cliente::where('nombre', 'Abandonado Uno')->first());
    }

    /**
     * FR-023 / FR-024: para Productos los números del resumen salen de la `ImportacionCorrida`, que
     * es el registro de lo que realmente quedó escrito — y la pantalla muestra de qué archivo y de
     * qué momento son.
     */
    public function test_el_resumen_de_productos_sale_de_la_corrida_y_coincide_con_la_base(): void
    {
        Storage::fake('local');

        $existente = Producto::factory()->create();

        $this->subir('productos', [
            ['Id', 'Nombre'],
            [null, 'Producto Nuevo Uno'],
            [null, 'Producto Nuevo Dos'],
            [$existente->id, 'Producto Renombrado'],
        ]);

        $this->importar('productos', [0 => 'id', 1 => 'nombre']);

        $resumen = $this->get(route('importacion.resumen', 'productos'));
        $resumen->assertOk();

        $corrida = $resumen->viewData('corrida');
        $this->assertNotNull($corrida);
        $this->assertSame('planilla.xlsx', $corrida->archivo_original);
        $this->assertSame(2, $corrida->filas_creadas);
        $this->assertSame(1, $corrida->filas_actualizadas);

        // Los números informados son los que quedaron en la base: 1 producto previo + 2 nuevos.
        $this->assertSame(3, $resumen->viewData('resultado')['importados'] + 0);
        $this->assertSame(3, Producto::count());
        $this->assertSame('Producto Renombrado', $existente->fresh()->nombre);

        $resumen->assertSee('planilla.xlsx');
    }
}
