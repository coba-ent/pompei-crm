<?php

namespace Tests\Unit;

use App\Services\Import\ImportadorClientes;
use App\Services\Import\ImportadorProductos;
use App\Services\Import\ImportadorProveedores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Parser/mapeo de importación tolerante a errores por fila (research D5/D6, FR-018). */
class ImportadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_clientes_crea_actualiza_y_acumula_errores_sin_perder_las_validas(): void
    {
        $importador = new ImportadorClientes;

        $resultado = $importador->importar([
            ['nombre' => 'Cliente Uno', 'cuit' => '20111111112'],
            ['nombre' => '', 'cuit' => '27111111117'],
            ['nombre' => 'Cliente Malo CUIT', 'cuit' => '20111111113'],
            ['nombre' => 'Cliente Sin Cuit'],
        ], 'crear')->toArray();

        $this->assertEquals(2, $resultado['creados']);
        $this->assertEquals(0, $resultado['actualizados']);
        $this->assertCount(2, $resultado['errores']);
    }

    public function test_clientes_actualiza_por_cuit_existente(): void
    {
        $importador = new ImportadorClientes;

        $importador->importar([['nombre' => 'Original', 'cuit' => '20111111112']], 'crear');

        $resultado = $importador->importar([
            ['nombre' => 'Actualizado', 'cuit' => '20111111112'],
        ], 'editar')->toArray();

        $this->assertEquals(0, $resultado['creados']);
        $this->assertEquals(1, $resultado['actualizados']);
        $this->assertDatabaseHas('clientes', ['cuit' => '20111111112', 'nombre' => 'Actualizado']);
    }

    public function test_proveedores_dedupe_por_cuit(): void
    {
        $importador = new ImportadorProveedores;

        $importador->importar([['nombre' => 'Prov Original', 'cuit' => '20111111112']], 'crear');
        $resultado = $importador->importar([['nombre' => 'Prov Actualizado', 'cuit' => '20111111112']], 'editar')->toArray();

        $this->assertEquals(1, $resultado['actualizados']);
    }

    public function test_productos_modo_editar_sin_codigo_existente_reporta_error_y_no_crea(): void
    {
        $importador = new ImportadorProductos;

        $resultado = $importador->importar([
            ['nombre' => 'Producto Fantasma', 'codigo' => 'NOEXISTE'],
        ], 'editar')->toArray();

        $this->assertEquals(0, $resultado['creados']);
        $this->assertCount(1, $resultado['errores']);
        $this->assertDatabaseMissing('productos', ['codigo' => 'NOEXISTE']);
    }

    public function test_productos_modo_crear_falla_si_el_codigo_ya_existe(): void
    {
        $importador = new ImportadorProductos;

        $importador->importar([['nombre' => 'Producto A', 'codigo' => 'ABC123']], 'crear');
        $resultado = $importador->importar([['nombre' => 'Producto B', 'codigo' => 'ABC123']], 'crear')->toArray();

        $this->assertEquals(0, $resultado['creados']);
        $this->assertCount(1, $resultado['errores']);
    }

    public function test_productos_modo_editar_actualiza_por_codigo(): void
    {
        $importador = new ImportadorProductos;

        $importador->importar([['nombre' => 'Producto Original', 'codigo' => 'XYZ']], 'crear');
        $resultado = $importador->importar([
            ['nombre' => 'Producto Renombrado', 'codigo' => 'XYZ'],
        ], 'editar')->toArray();

        $this->assertEquals(1, $resultado['actualizados']);
        $this->assertDatabaseHas('productos', ['codigo' => 'XYZ', 'nombre' => 'Producto Renombrado']);
    }
}
