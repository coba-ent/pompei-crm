<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\PivotExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * spec 069 — Excel del cruce visible.
 *
 * Lo que se prueba es que el archivo **reproduzca la matriz recibida**, no que la recalcule: el
 * usuario pudo excluir valores con el embudo o reordenar dimensiones, y eso vive sólo en el
 * navegador. Un export que recalculara daría un archivo distinto al que está viendo.
 */
class PivotExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function matriz(): array
    {
        return [
            'titulo' => 'Ranking de Clientes',
            'encabezados_fila' => ['Clientes'],
            'encabezados_columna' => ['2026 › Ago', '2026 › Sep'],
            'filas' => [
                ['etiqueta' => ['Juan Pérez'], 'valores' => [1000.5, 200.0], 'total' => 1200.5],
                ['etiqueta' => ['Ana Gómez'], 'valores' => [300.0, null], 'total' => 300.0],
            ],
            'totales_columna' => [1300.5, 200.0],
            'total_general' => 1500.5,
        ];
    }

    private function hojas(array $matriz): array
    {
        // `Excel::raw` no expone las hojas por separado; se arman con el propio export, que es lo
        // que se quiere verificar.
        $export = new PivotExport($matriz);
        $hojas = $export->sheets();

        return array_map(fn ($h) => $h->array(), $hojas);
    }

    public function test_la_hoja_legible_reproduce_la_matriz_recibida(): void
    {
        [$legible] = $this->hojas($this->matriz());

        // Encabezados: dimensión de fila + las columnas del cruce + Total.
        $this->assertSame(['Clientes', '2026 › Ago', '2026 › Sep', 'Total'], $legible[0]);

        $this->assertSame(['Juan Pérez', 1000.5, 200.0, 1200.5], $legible[1]);
        $this->assertSame(['Ana Gómez', 300.0, null, 300.0], $legible[2]);
    }

    public function test_la_hoja_legible_cierra_con_la_fila_de_totales(): void
    {
        [$legible] = $this->hojas($this->matriz());
        $ultima = end($legible);

        $this->assertSame('Total', $ultima[0]);
        $this->assertContains(1500.5, $ultima, 'el total general tiene que estar en la fila de cierre');
    }

    public function test_la_hoja_plana_tiene_una_fila_por_combinacion_con_valor(): void
    {
        [, $plana] = $this->hojas($this->matriz());

        $this->assertSame(['Fila', 'Columna', 'Valor'], $plana[0]);

        // Tres combinaciones con valor: la celda vacía de Ana en septiembre NO se emite.
        $this->assertCount(4, $plana, 'encabezado + 3 celdas con valor');
        $this->assertSame(['Juan Pérez', '2026 › Ago', 1000.5], $plana[1]);
        $this->assertSame(['Juan Pérez', '2026 › Sep', 200.0], $plana[2]);
        $this->assertSame(['Ana Gómez', '2026 › Ago', 300.0], $plana[3]);
    }

    public function test_un_cruce_con_dos_dimensiones_en_filas_conserva_las_dos_etiquetas(): void
    {
        $matriz = $this->matriz();
        $matriz['encabezados_fila'] = ['Clientes', 'Productos'];
        $matriz['filas'] = [
            ['etiqueta' => ['Juan Pérez', 'Camisa'], 'valores' => [500.0, null], 'total' => 500.0],
        ];

        [$legible, $plana] = $this->hojas($matriz);

        $this->assertSame(['Clientes', 'Productos', '2026 › Ago', '2026 › Sep', 'Total'], $legible[0]);
        $this->assertSame(['Juan Pérez', 'Camisa', 500.0, null, 500.0], $legible[1]);

        // En la hoja plana las dos dimensiones se juntan en una sola etiqueta legible.
        $this->assertSame('Juan Pérez › Camisa', $plana[1][0]);
    }

    public function test_el_endpoint_descarga_un_xlsx(): void
    {
        Excel::fake();

        $this->postJson(route('informes.ventas.pivot.exportar'), $this->matriz())->assertOk();

        Excel::assertDownloaded('Ranking de Clientes '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }

    public function test_el_endpoint_rechaza_un_cuerpo_sin_filas(): void
    {
        // Protección contra un POST armado a mano fuera del flujo de la UI.
        $matriz = $this->matriz();
        $matriz['filas'] = [];

        $this->postJson(route('informes.ventas.pivot.exportar'), $matriz)
            ->assertStatus(422)
            ->assertJsonValidationErrors('filas');
    }
}
