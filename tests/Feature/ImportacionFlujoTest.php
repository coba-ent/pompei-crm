<?php

namespace Tests\Feature;

use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Flujo HTTP completo de importación (subir → vista previa → confirmar / cancelar), FR-016/017/018/019/020. */
class ImportacionFlujoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        $this->user()->roles()->attach($admin->id);
    }

    private function user(): \App\Models\User
    {
        return auth()->user();
    }

    private function archivoClientesXlsx(array $filas): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $fila => $valores) {
            foreach ($valores as $columna => $valor) {
                $sheet->setCellValueByColumnAndRow($columna + 1, $fila + 1, $valor);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_flujo_completo_sube_previsualiza_y_confirma_tolerando_errores(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoClientesXlsx([
            ['Nombre', 'CUIT'],
            ['Cliente Uno', '20111111112'],
            ['', '27111111117'],
        ]);

        $subida = $this->postJson(route('configuracion.importar.upload'), [
            'archivo' => $archivo,
            'tipo' => 'clientes',
            'modo' => 'crear',
        ])->assertOk()->json();

        $this->assertEquals(['Nombre', 'CUIT'], $subida['columnas']);
        $this->assertCount(2, $subida['preview']);

        $confirmacion = $this->postJson(
            route('configuracion.importar.confirmar', $subida['importacion_id']),
            ['mapeo' => ['Nombre' => 'nombre', 'CUIT' => 'cuit']]
        )->assertOk()->json();

        $this->assertEquals(1, $confirmacion['resumen']['creados']);
        $this->assertCount(1, $confirmacion['resumen']['errores']);
        $this->assertDatabaseHas('clientes', ['cuit' => '20111111112']);
        $this->assertDatabaseHas('importaciones', ['estado' => 'procesada', 'filas_procesadas' => 1, 'filas_error' => 1]);
    }

    public function test_rechaza_formato_de_archivo_no_soportado(): void
    {
        $archivo = UploadedFile::fake()->create('clientes.txt', 10, 'text/plain');

        $this->postJson(route('configuracion.importar.upload'), [
            'archivo' => $archivo,
            'tipo' => 'clientes',
            'modo' => 'crear',
        ])->assertStatus(422);
    }

    public function test_cancelar_no_crea_registros_y_marca_cancelada(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoClientesXlsx([
            ['Nombre', 'CUIT'],
            ['Cliente Uno', '20111111112'],
        ]);

        $subida = $this->postJson(route('configuracion.importar.upload'), [
            'archivo' => $archivo,
            'tipo' => 'clientes',
            'modo' => 'crear',
        ])->assertOk()->json();

        $this->postJson(route('configuracion.importar.cancelar', $subida['importacion_id']))
            ->assertOk();

        $this->assertDatabaseHas('importaciones', ['id' => $subida['importacion_id'], 'estado' => 'cancelada']);
        $this->assertDatabaseCount('clientes', 0);
    }
}
