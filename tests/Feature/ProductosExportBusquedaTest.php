<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * El export de Productos tiene que traer las mismas filas que el listado para la misma
 * búsqueda. Antes le sumaba al filtro flexible (palabra por palabra) un LIKE con la frase
 * entera, así que "andina largo bl" mostraba 2 productos en pantalla y exportaba la hoja vacía.
 */
class ProductosExportBusquedaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_busqueda_por_palabras_desordenadas_exporta_lo_mismo_que_lista(): void
    {
        Producto::factory()->create(['nombre' => 'Inodoro largo Andina 4,5 lts blanco', 'codigo' => 'AND-IN-002-BL']);
        Producto::factory()->create(['nombre' => 'Bidet corto Ferrum', 'codigo' => 'FER-BI-001']);

        $descarga = $this->actingAs(User::factory()->create())
            ->get(route('productos.export', ['buscar' => 'andina largo bl']));
        $descarga->assertOk();

        $ruta = tempnam(sys_get_temp_dir(), 'expbusq').'.xlsx';
        file_put_contents($ruta, $descarga->streamedContent());
        $filas = IOFactory::load($ruta)->getActiveSheet()->toArray();
        @unlink($ruta);

        $datos = array_values(array_filter(array_slice($filas, 1), fn ($f) => array_filter($f, fn ($v) => $v !== null && $v !== '')));
        $this->assertCount(1, $datos);
        $this->assertContains('Inodoro largo Andina 4,5 lts blanco', $datos[0]);
    }
}
