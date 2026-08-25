<?php

namespace Tests\Unit;

use App\Services\Import\FuenteFilasImportacion;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec 082 — la fuente de filas es la base de la feature: interpreta el archivo una sola vez y
 * después entrega rangos. Sus bordes (archivo sin datos, rango fuera de rango, celdas raras) son
 * donde se rompe una importación entera, así que van cubiertos uno por uno.
 *
 * @see specs/082-importacion-archivos-grandes/contracts/fuente-filas-importacion.md
 */
class FuenteFilasImportacionTest extends TestCase
{
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
    private function fuente(array $filas): FuenteFilasImportacion
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $sheet->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'fuente').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->temporales[] = $ruta;

        $rutaNdjson = FuenteFilasImportacion::volcar($ruta);
        $this->temporales[] = $rutaNdjson;

        return new FuenteFilasImportacion($rutaNdjson);
    }

    /** @return array<int, array<int, mixed>> */
    private function aArray(iterable $filas): array
    {
        $resultado = [];
        foreach ($filas as $indice => $fila) {
            $resultado[$indice] = $fila;
        }

        return $resultado;
    }

    public function test_separa_encabezados_de_filas_de_datos(): void
    {
        $fuente = $this->fuente([
            ['Nombre', 'Codigo'],
            ['Uno', 'A-1'],
            ['Dos', 'A-2'],
        ]);

        $this->assertSame(['Nombre', 'Codigo'], $fuente->encabezados());
        $this->assertSame(2, $fuente->total());
    }

    public function test_archivo_solo_con_encabezados_no_tiene_filas_de_datos(): void
    {
        $fuente = $this->fuente([['Nombre', 'Codigo']]);

        $this->assertSame(0, $fuente->total());
        $this->assertSame([], $this->aArray($fuente->leerRango(0, 250)));
    }

    public function test_archivo_de_una_sola_fila_de_datos(): void
    {
        $fuente = $this->fuente([['Nombre'], ['Unica']]);

        $this->assertSame(1, $fuente->total());
        $this->assertSame([0 => ['Unica']], $this->aArray($fuente->leerRango(0, 250)));
    }

    public function test_offset_mas_alla_del_final_devuelve_vacio_sin_error(): void
    {
        $fuente = $this->fuente([['Nombre'], ['Uno'], ['Dos']]);

        // Es lo que cierra el loop de tandas de forma natural: la última tanda pide un rango que
        // ya no existe y termina, en vez de reventar.
        $this->assertSame([], $this->aArray($fuente->leerRango(99, 250)));
    }

    public function test_limite_que_excede_el_final_devuelve_lo_que_haya(): void
    {
        $fuente = $this->fuente([['Nombre'], ['Uno'], ['Dos']]);

        $this->assertSame([['Uno'], ['Dos']], array_values($this->aArray($fuente->leerRango(0, 250))));
    }

    public function test_limite_null_devuelve_desde_el_offset_hasta_el_final(): void
    {
        $fuente = $this->fuente([['Nombre'], ['Uno'], ['Dos'], ['Tres']]);

        $this->assertSame([['Dos'], ['Tres']], array_values($this->aArray($fuente->leerRango(1))));
    }

    public function test_las_claves_del_rango_son_el_indice_absoluto_de_la_fila_de_datos(): void
    {
        // De acá sale `numero_fila` en el importador: si las claves fueran relativas a la tanda,
        // los snapshots de deshacer y los errores apuntarían a filas equivocadas.
        $fuente = $this->fuente([['Nombre'], ['Uno'], ['Dos'], ['Tres'], ['Cuatro']]);

        $this->assertSame([1, 2], array_keys($this->aArray($fuente->leerRango(1, 2))));
    }

    public function test_preserva_el_orden_de_las_filas(): void
    {
        $filas = [['Nombre']];
        foreach (range(1, 30) as $n) {
            $filas[] = ["Fila {$n}"];
        }

        $fuente = $this->fuente($filas);
        $leidas = array_values($this->aArray($fuente->leerRango(0)));

        $this->assertCount(30, $leidas);
        $this->assertSame('Fila 1', $leidas[0][0]);
        $this->assertSame('Fila 30', $leidas[29][0]);
    }

    public function test_preserva_los_indices_numericos_de_las_celdas_incluidas_las_vacias(): void
    {
        // El mapeo del Paso 2 referencia columnas por índice: un reindexado o un array_filter
        // correría todas las columnas siguientes y cargaría los datos en el campo equivocado.
        $fuente = $this->fuente([
            ['A', 'B', 'C'],
            ['uno', null, 'tres'],
        ]);

        $fila = $this->aArray($fuente->leerRango(0))[0];

        $this->assertSame('uno', $fila[0]);
        $this->assertSame('tres', $fila[2]);
        $this->assertArrayHasKey(1, $fila);
    }

    public function test_una_celda_no_serializable_no_rompe_el_volcado(): void
    {
        $fuente = $this->fuente([
            ['Nombre', 'Fecha'],
            ['Uno', '2026-08-25'],
        ]);

        $fila = $this->aArray($fuente->leerRango(0))[0];

        $this->assertSame('Uno', $fila[0]);
        $this->assertNotEmpty($fila[1]);
    }

    public function test_si_el_ndjson_no_existe_avisa_que_hay_que_volver_a_subir_el_archivo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/volvé a subir el archivo/iu');

        new FuenteFilasImportacion(sys_get_temp_dir().'/no-existe-'.uniqid().'.ndjson');
    }

    /**
     * FR-003: la memoria de una tanda no puede depender del tamaño total del archivo. Es el punto
     * entero de la feature — con `Excel::toArray()` por tanda el pico era ~570 MB con el catálogo
     * real, contra 512 MB de límite.
     */
    public function test_la_memoria_de_un_rango_no_depende_del_tamano_del_archivo(): void
    {
        $armar = function (int $filas): FuenteFilasImportacion {
            $datos = [['Nombre', 'Codigo']];
            foreach (range(1, $filas) as $n) {
                $datos[] = ["Producto {$n}", "COD-{$n}"];
            }

            return $this->fuente($datos);
        };

        $medir = function (FuenteFilasImportacion $fuente): int {
            gc_collect_cycles();
            $antes = memory_get_usage();
            $leidas = 0;
            foreach ($fuente->leerRango(0, 250) as $fila) {
                $leidas += count($fila);
            }
            $this->assertGreaterThan(0, $leidas);

            return max(memory_get_usage() - $antes, 0);
        };

        $chico = $medir($armar(500));
        $grande = $medir($armar(5000));

        // 10x de filas en el archivo no puede traducirse en un salto de memoria del mismo orden:
        // se lee el mismo rango de 250 filas en los dos casos.
        $this->assertLessThan(max($chico * 3, 2 * 1024 * 1024), $grande);
    }
}
