<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Auto-mapeo del asistente de importación de Productos (spec 074).
 *
 * Fija el **round-trip**: los encabezados que escribe la exportación de Productos tienen que
 * mapearse solos al reimportar. El caso que motivó el test son las columnas de stock, que la
 * exportación escribe como "Stock {depósito}" mientras el importador sólo conocía el alias con el
 * nombre pelado del depósito — el asistente las dejaba sin mapear y el stock nunca se actualizaba.
 */
class ImportacionAutomapeoProductosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function archivoConEncabezados(array $encabezados): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($encabezados as $c => $titulo) {
            $sheet->setCellValueByColumnAndRow($c + 1, 1, $titulo);
            $sheet->setCellValueByColumnAndRow($c + 1, 2, '1');
        }

        $path = tempnam(sys_get_temp_dir(), 'automap').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return array<int, string> índice de columna => campo sugerido */
    private function sugerenciasPara(array $encabezados): array
    {
        // `subir()` redirige al paso 2 y `mapear()` devuelve una vista: las sugerencias se leen
        // de los datos de esa vista, que es exactamente lo que alimenta los <select> del asistente.
        $this->post(route('importacion.subir', 'productos'), [
            'archivo' => $this->archivoConEncabezados($encabezados),
        ])->assertRedirect(route('importacion.mapear', 'productos'));

        return $this->get(route('importacion.mapear', 'productos'))
            ->assertOk()
            ->viewData('sugerencias');
    }

    public function test_los_encabezados_de_stock_de_la_exportacion_se_automapean(): void
    {
        $local = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $full = Deposito::create(['nombre' => 'Full', 'activo' => true]);

        // Encabezados TAL CUAL los escribe la exportación de Productos.
        $sugerencias = $this->sugerenciasPara(['Id', 'Nombre', 'Stock total', 'Stock Local', 'Stock Full']);

        $this->assertSame('id', $sugerencias[0]);
        $this->assertSame('nombre', $sugerencias[1]);
        $this->assertSame('stock_total_verificacion', $sugerencias[2]);
        $this->assertSame("stock_deposito_{$local->id}", $sugerencias[3]);
        $this->assertSame("stock_deposito_{$full->id}", $sugerencias[4]);
    }

    /** El alias viejo (el nombre pelado del depósito) tiene que seguir funcionando. */
    public function test_el_nombre_pelado_del_deposito_sigue_automapeando(): void
    {
        $local = Deposito::create(['nombre' => 'Local', 'activo' => true]);

        $sugerencias = $this->sugerenciasPara(['Nombre', 'Local']);

        $this->assertSame('nombre', $sugerencias[0]);
        $this->assertSame("stock_deposito_{$local->id}", $sugerencias[1]);
    }

    /** Las listas de precio automapean por el nombre de la lista (comportamiento ya vigente). */
    public function test_las_listas_de_precio_automapean_por_su_nombre(): void
    {
        $pvp = ListaPrecio::create(['nombre' => 'PVP', 'activo' => true]);
        $may = ListaPrecio::create(['nombre' => 'Mayorista/obras', 'activo' => true]);

        $sugerencias = $this->sugerenciasPara(['Nombre', 'PVP', 'Mayorista/obras']);

        $this->assertSame("precio_lista_{$pvp->id}", $sugerencias[1]);
        $this->assertSame("precio_lista_{$may->id}", $sugerencias[2]);
    }

    /** Una columna desconocida sigue quedando sin mapear (no hay matching aproximado). */
    public function test_una_columna_desconocida_queda_sin_mapear(): void
    {
        Deposito::create(['nombre' => 'Local', 'activo' => true]);

        $sugerencias = $this->sugerenciasPara(['Nombre', 'Columna Que No Existe']);

        $this->assertSame('nombre', $sugerencias[0]);
        $this->assertArrayNotHasKey(1, $sugerencias);
    }
}
