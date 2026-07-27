<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Asistente "Importar Datos" (subir → mapear → confirmar/cancelar → resumen).
 * FR-001 a FR-011, Principio IV (validación por fila, atomicidad por fila).
 */
class ImportacionDatosTest extends TestCase
{
    use RefreshDatabase;

    private function archivoXlsx(array $filas, string $nombre = 'archivo.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $fila => $valores) {
            foreach (array_values($valores) as $columna => $valor) {
                $sheet->setCellValueByColumnAndRow($columna + 1, $fila + 1, $valor);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            $nombre,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_sube_archivo_valido_y_muestra_vista_previa(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Email'],
            ['Cliente Uno', 'uno@test.com'],
        ]);

        $response = $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response->assertRedirect(route('importacion.mapear', 'clientes'));

        $vistaPrevia = $this->get(route('importacion.mapear', 'clientes'));
        $vistaPrevia->assertOk();
        $vistaPrevia->assertSee('Nombre');
        $vistaPrevia->assertSee('Cliente Uno');
    }

    public function test_rechaza_archivo_con_extension_no_soportada(): void
    {
        $archivo = UploadedFile::fake()->create('clientes.pdf', 100, 'application/pdf');

        $response = $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response->assertSessionHasErrors('archivo');
        $response->assertRedirect();
        $this->assertNotEquals(route('importacion.mapear', 'clientes'), $response->headers->get('Location'));
    }

    public function test_rechaza_archivo_de_mas_de_10mb(): void
    {
        $archivo = UploadedFile::fake()->create('clientes.xlsx', 10241, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response->assertSessionHasErrors('archivo');
    }

    public function test_confirmar_mapeo_valido_crea_cliente_por_fila_valida_incluyendo_campo_personalizado(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'CUIT', 'Rubro'],
            ['Cliente Uno', '20111111112', 'Ferretería'],
            ['Cliente Dos', '20111111113', 'Kiosco'], // CUIT matemáticamente inválido
        ]);

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'cuit', '2' => 'personalizado'],
            'personalizados' => ['2' => 'Rubro'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));

        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Uno', 'cuit' => '20111111112']);
        $this->assertDatabaseCount('clientes', 1);

        $creado = Cliente::where('nombre', 'Cliente Uno')->firstOrFail();
        $this->assertSame('Rubro', $creado->campos_personalizados[0]['nombre']);
        $this->assertSame('Ferretería', $creado->campos_personalizados[0]['valor']);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertOk();
        $resumen->assertSee('1');
        $resumen->assertSee('CUIT');
    }

    public function test_confirmar_rechaza_mapeo_sin_campo_obligatorio_o_con_duplicado(): void
    {
        Storage::fake('local');

        // Caso 1: campo obligatorio (Nombre) sin mapear.
        $archivo = $this->archivoXlsx([
            ['Email'],
            ['uno@test.com'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);
        $respuesta1 = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'email'],
        ]);
        $respuesta1->assertRedirect(route('importacion.mapear', 'clientes'));
        $respuesta1->assertSessionHasErrors('mapeo');
        $this->assertDatabaseCount('clientes', 0);

        // Caso 2: dos columnas mapeadas al mismo campo destino.
        $archivo2 = $this->archivoXlsx([
            ['Nombre', 'Nombre Comercial'],
            ['Cliente Uno', 'Cliente Uno SA'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo2]);
        $respuesta2 = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'nombre'],
        ]);
        $respuesta2->assertRedirect(route('importacion.mapear', 'clientes'));
        $respuesta2->assertSessionHasErrors('mapeo');
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_cancelar_no_crea_clientes_y_borra_el_archivo_temporal(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre'],
            ['Cliente Uno'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $this->assertCount(1, Storage::disk('local')->files('imports'));

        $response = $this->post(route('importacion.cancelar', 'clientes'));

        $response->assertRedirect(route('importacion.index', 'clientes'));
        $this->assertDatabaseCount('clientes', 0);
        $this->assertCount(0, Storage::disk('local')->files('imports'));
    }

    public function test_proveedores_confirmar_mapeo_valido_crea_proveedor_por_fila(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Proveedor', 'Nota Interna'],
            ['Distribuidora Sur', 'Paga a 30 días'],
        ]);
        $this->post(route('importacion.subir', 'proveedores'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'proveedores'), [
            'mapeo' => ['0' => 'nombre', '1' => 'nota'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'proveedores'));
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Distribuidora Sur', 'nota' => 'Paga a 30 días']);
    }

    public function test_productos_acepta_costo_y_precio_con_coma_decimal_formato_argentino(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Costo', 'Precio de Venta'],
            ['Producto AR', '69320,16', '126036,65'],
            ['Producto Miles', '1.234,56', '2.345,67'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'nombre', '1' => 'costo', '2' => 'precio_venta'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto AR', 'costo' => 69320.16, 'precio_venta' => 126036.65]);
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto Miles', 'costo' => 1234.56, 'precio_venta' => 2345.67]);
    }

    public function test_productos_resuelve_proveedor_por_nombre_y_advierte_si_no_matchea(): void
    {
        Storage::fake('local');
        Proveedor::factory()->create(['nombre' => 'Acme SA']);

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Proveedor'],
            ['Producto Uno', 'acme sa'], // coincide sin distinguir mayúsculas
            ['Producto Dos', 'Proveedor Inexistente'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'nombre', '1' => 'proveedor_id'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));

        $productoUno = Producto::where('nombre', 'Producto Uno')->firstOrFail();
        $this->assertSame('Acme SA', $productoUno->proveedor->nombre);

        $productoDos = Producto::where('nombre', 'Producto Dos')->firstOrFail();
        $this->assertNull($productoDos->proveedor_id);

        $this->assertDatabaseCount('productos', 2);
    }

    public function test_productos_usa_tipo_producto_por_defecto_si_no_mapeado_o_vacio(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Tipo'],
            ['Producto Sin Tipo Mapeado', ''],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'nombre'], // columna "Tipo" (índice 1) no se mapea
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto Sin Tipo Mapeado', 'tipo' => 'producto']);
    }
}
