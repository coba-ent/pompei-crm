<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Import\DefinicionCamposImportables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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

    /**
     * Igual que `archivoXlsx()`, pero permite marcar celdas puntuales como fecha nativa
     * de Excel (número de serie con formato de fecha) para probar `normalizarFecha()`
     * contra el formato real que exporta Excel/Sheets — no como texto.
     *
     * @param  array<int, array{fila: int, col: int}>  $celdasFecha  posiciones (0-based) cuyo valor ya viene como \DateTime en $filas
     */
    private function archivoXlsxConFechaNativa(array $filas, array $celdasFecha, string $nombre = 'archivo.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $fila => $valores) {
            foreach (array_values($valores) as $columna => $valor) {
                $esFecha = collect($celdasFecha)->contains(fn ($c) => $c['fila'] === $fila && $c['col'] === $columna);
                if ($esFecha && $valor instanceof \DateTimeInterface) {
                    $sheet->setCellValueByColumnAndRow($columna + 1, $fila + 1, ExcelDate::PHPToExcel($valor));
                    $sheet->getStyleByColumnAndRow($columna + 1, $fila + 1)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
                } else {
                    $sheet->setCellValueByColumnAndRow($columna + 1, $fila + 1, $valor);
                }
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

    // ── US1: Clientes con todos sus datos comerciales y fiscales (spec 026) ──────────

    public function test_clientes_importa_bloque_fiscal_nota_ml_y_pagina_web(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Razón Social', 'Tipo de Documento', 'Domicilio Fiscal', 'Localidad Fiscal', 'Provincia Fiscal', 'CP Fiscal', 'Teléfono Fiscal', 'Teléfono Celular Fiscal', 'CP', 'Nota para Ventas', 'Descuento General', 'Usuario ML', 'Página Web'],
            ['Cliente Uno', 'Cliente Uno SA', 'DNI', 'Av. Siempre Viva 123', 'Springfield', 'Buenos Aires', '1900', '011-4444', '011-15-5555', '1900', 'Nota comercial', '10,5', 'clienteuno_ml', 'https://clienteuno.com'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => [
                '0' => 'nombre', '1' => 'razon_social', '2' => 'tipo_documento', '3' => 'domicilio_fiscal',
                '4' => 'localidad_fiscal', '5' => 'provincia_fiscal', '6' => 'cp_fiscal', '7' => 'telefono_fiscal',
                '8' => 'telefono_celular_fiscal', '9' => 'cp', '10' => 'nota_cliente', '11' => 'descuento_general_pct',
                '12' => 'apodo_ml', '13' => 'pagina_web',
            ],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente Uno',
            'razon_social' => 'Cliente Uno SA',
            'tipo_documento' => 'DNI',
            'domicilio_fiscal' => 'Av. Siempre Viva 123',
            'localidad_fiscal' => 'Springfield',
            'provincia_fiscal' => 'Buenos Aires',
            'cp_fiscal' => '1900',
            'telefono_fiscal' => '011-4444',
            'telefono_celular_fiscal' => '011-15-5555',
            'cp' => '1900',
            'nota_cliente' => 'Nota comercial',
            'descuento_general_pct' => 10.5,
            'apodo_ml' => 'clienteuno_ml',
            'pagina_web' => 'https://clienteuno.com',
        ]);
    }

    public function test_clientes_saldo_inicial_y_fecha_en_los_3_formatos_aceptados(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsxConFechaNativa([
            ['Nombre', 'Saldo Inicial', 'Fecha de Saldo Inicial'],
            ['Cliente Nativa', '1.234,56', new \DateTime('2026-03-15')],
            ['Cliente DDMMYYYY', '500', '20/02/2026'],
            ['Cliente YYYYMMDD', '750', '2026-01-05'],
            ['Cliente Fecha Invalida', '100', 'no es una fecha'],
        ], [
            ['fila' => 1, 'col' => 2],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'saldo_inicial', '2' => 'saldo_inicial_fecha'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));

        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Nativa', 'saldo_inicial' => 1234.56, 'saldo_inicial_fecha' => '2026-03-15']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente DDMMYYYY', 'saldo_inicial_fecha' => '2026-02-20']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente YYYYMMDD', 'saldo_inicial_fecha' => '2026-01-05']);
        $this->assertDatabaseMissing('clientes', ['nombre' => 'Cliente Fecha Invalida']);
        $this->assertDatabaseCount('clientes', 3);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertOk();
        $resumen->assertSee('1');
    }

    public function test_clientes_lista_de_precios_por_nombre_con_advertencia_si_no_matchea(): void
    {
        Storage::fake('local');
        ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Lista de Precios'],
            ['Cliente Con Lista', 'mayorista'], // coincide sin distinguir mayúsculas
            ['Cliente Sin Lista', 'Lista Inexistente'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'lista_precio_id'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));

        $conLista = Cliente::where('nombre', 'Cliente Con Lista')->firstOrFail();
        $this->assertSame('Mayorista', $conLista->listaPrecio->nombre);

        $sinLista = Cliente::where('nombre', 'Cliente Sin Lista')->firstOrFail();
        $this->assertNull($sinLista->lista_precio_id);

        $this->assertDatabaseCount('clientes', 2);
    }

    // ── US2: Proveedores con el mismo bloque fiscal + saldo (spec 026) ───────────────

    public function test_proveedores_importa_bloque_fiscal_y_saldo_inicial_con_fecha(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Proveedor', 'Razón Social', 'Domicilio Fiscal', 'Saldo Inicial', 'Fecha de Saldo Inicial'],
            ['Distribuidora Sur', 'Distribuidora Sur SRL', 'Ruta 3 Km 45', '2.500,75', '10/04/2026'],
        ]);
        $this->post(route('importacion.subir', 'proveedores'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'proveedores'), [
            'mapeo' => ['0' => 'nombre', '1' => 'razon_social', '2' => 'domicilio_fiscal', '3' => 'saldo_inicial', '4' => 'saldo_inicial_fecha'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'proveedores'));
        $this->assertDatabaseHas('proveedores', [
            'nombre' => 'Distribuidora Sur',
            'razon_social' => 'Distribuidora Sur SRL',
            'domicilio_fiscal' => 'Ruta 3 Km 45',
            'saldo_inicial' => 2500.75,
            'saldo_inicial_fecha' => '2026-04-10',
        ]);
    }

    public function test_proveedores_no_ofrece_campos_exclusivos_de_clientes(): void
    {
        $campos = DefinicionCamposImportables::proveedores();

        $this->assertArrayNotHasKey('apodo_ml', $campos);
        $this->assertArrayNotHasKey('nota_cliente', $campos);
        $this->assertArrayNotHasKey('descuento_general_pct', $campos);
        $this->assertArrayNotHasKey('lista_precio_id', $campos);
    }

    // ── US3: Productos con Activo/Mostrar en Ventas/Mostrar en Compras (spec 026) ────

    public function test_productos_booleanos_activo_mostrar_ventas_mostrar_compras(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Activo', 'Mostrar en Ventas', 'Mostrar en Compras'],
            ['Producto Si No', 'Si', 'No', 'Si'],
            ['Producto 1 0', '1', '0', '1'],
            ['Producto True False', 'true', 'false', 'true'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'nombre', '1' => 'activo', '2' => 'mostrar_en_ventas', '3' => 'mostrar_en_compras'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto Si No', 'activo' => true, 'mostrar_en_ventas' => false, 'mostrar_en_compras' => true]);
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto 1 0', 'activo' => true, 'mostrar_en_ventas' => false, 'mostrar_en_compras' => true]);
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto True False', 'activo' => true, 'mostrar_en_ventas' => false, 'mostrar_en_compras' => true]);
    }

    public function test_productos_booleano_vacio_usa_default_e_invalido_falla_la_fila(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Activo'],
            ['Producto Sin Mapear', ''],
            ['Producto Invalido', 'tal vez'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'nombre', '1' => 'activo'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['nombre' => 'Producto Sin Mapear', 'activo' => true]);
        $this->assertDatabaseMissing('productos', ['nombre' => 'Producto Invalido']);
        $this->assertDatabaseCount('productos', 1);
    }

    // ── US1 (spec 027): actualizar Clientes por Id sin recrearlos ────────────────────

    public function test_clientes_actualiza_por_id_saldo_inicial_y_conserva_el_resto(): void
    {
        Storage::fake('local');
        $cliente = Cliente::factory()->create([
            'nombre' => 'Cliente Original', 'email' => 'original@test.com', 'domicilio' => 'Calle Falsa 123', 'saldo_inicial' => 0,
        ]);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Saldo Inicial'],
            [(string) $cliente->id, '', '1.500,50'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'saldo_inicial'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseCount('clientes', 1);
        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'nombre' => 'Cliente Original',
            'email' => 'original@test.com',
            'domicilio' => 'Calle Falsa 123',
            'saldo_inicial' => 1500.50,
        ]);
    }

    public function test_clientes_id_sin_match_crea_el_cliente_preservando_ese_id(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Saldo Inicial'],
            ['999999', 'Cliente Migrado', '100'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'saldo_inicial'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['id' => 999999, 'nombre' => 'Cliente Migrado', 'saldo_inicial' => 100]);
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_clientes_id_sin_match_reimportado_despues_actualiza_en_vez_de_duplicar(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre'],
            ['999999', 'Cliente Migrado'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);
        $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre'],
        ]);
        $this->assertDatabaseCount('clientes', 1);

        // Reimportar el mismo archivo: el id ya existe → actualiza, no duplica.
        $archivo2 = $this->archivoXlsx([
            ['Id', 'Nombre'],
            ['999999', 'Cliente Migrado Corregido'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo2]);
        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['id' => 999999, 'nombre' => 'Cliente Migrado Corregido']);
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_clientes_id_sin_match_con_celda_nombre_vacia_falla_la_fila_igual_que_cualquier_alta(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Saldo Inicial'],
            ['999999', '', '100'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'saldo_inicial'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_clientes_id_no_numerico_o_no_entero_son_filas_fallidas(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Saldo Inicial'],
            ['abc', '', '100'],
            ['5,5', '', '100'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'saldo_inicial'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseCount('clientes', 0);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertSee('2');
    }

    public function test_clientes_actualizacion_con_celda_nombre_vacia_no_exige_obligatorio(): void
    {
        Storage::fake('local');
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Actual']);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Email'],
            [(string) $cliente->id, '', 'nuevo@test.com'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'email'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'nombre' => 'Cliente Actual', 'email' => 'nuevo@test.com']);
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_clientes_actualizacion_remapea_su_propio_cuit_sin_fallar_por_unicidad(): void
    {
        Storage::fake('local');
        $cliente = Cliente::factory()->create(['cuit' => '20111111112']);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'CUIT'],
            [(string) $cliente->id, '', '20111111112'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'cuit'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'cuit' => '20111111112']);
        $this->assertDatabaseCount('clientes', 1);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertDontSee('no importada');
    }

    public function test_clientes_id_vacio_en_una_fila_se_procesa_como_alta_nueva(): void
    {
        Storage::fake('local');
        $cliente = Cliente::factory()->create();

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre'],
            [(string) $cliente->id, 'Cliente Actualizado'],
            ['', 'Cliente Nuevo'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'nombre' => 'Cliente Actualizado']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Nuevo']);
        $this->assertDatabaseCount('clientes', 2);
    }

    // ── US2 (spec 027): mismo mecanismo para Proveedores y Productos ─────────────────

    public function test_proveedores_actualiza_por_id_saldo_inicial_y_conserva_el_resto(): void
    {
        Storage::fake('local');
        $proveedor = Proveedor::factory()->create(['nombre' => 'Proveedor Original', 'saldo_inicial' => 0]);

        $archivo = $this->archivoXlsx([
            ['Id', 'Proveedor', 'Saldo Inicial'],
            [(string) $proveedor->id, '', '2000'],
        ]);
        $this->post(route('importacion.subir', 'proveedores'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'proveedores'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'saldo_inicial'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'proveedores'));
        $this->assertDatabaseCount('proveedores', 1);
        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id, 'nombre' => 'Proveedor Original', 'saldo_inicial' => 2000]);
    }

    public function test_productos_actualiza_por_id_mostrar_en_ventas_y_conserva_precio_costo_stock(): void
    {
        Storage::fake('local');
        $producto = Producto::factory()->create([
            'nombre' => 'Producto Original', 'precio_venta' => 1000, 'costo' => 500, 'mostrar_en_ventas' => true,
        ]);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Mostrar en Ventas'],
            [(string) $producto->id, '', 'No'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'mostrar_en_ventas'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseCount('productos', 1);
        $this->assertDatabaseHas('productos', [
            'id' => $producto->id, 'nombre' => 'Producto Original', 'precio_venta' => 1000, 'costo' => 500, 'mostrar_en_ventas' => false,
        ]);
    }

    public function test_productos_actualizacion_remapea_su_propio_codigo_sin_fallar_por_unicidad(): void
    {
        Storage::fake('local');
        $producto = Producto::factory()->create(['codigo' => 'SKU-001']);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Código/SKU'],
            [(string) $producto->id, '', 'SKU-001'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'codigo'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'codigo' => 'SKU-001']);
        $this->assertDatabaseCount('productos', 1);

        $resumen = $this->get(route('importacion.resumen', 'productos'));
        $resumen->assertDontSee('no importada');
    }

    public function test_productos_actualizacion_sin_mapear_proveedor_no_toca_el_existente(): void
    {
        Storage::fake('local');
        $proveedor = Proveedor::factory()->create();
        $producto = Producto::factory()->create(['proveedor_id' => $proveedor->id, 'precio_venta' => 1000]);

        $archivo = $this->archivoXlsx([
            ['Id', 'Nombre', 'Precio de Venta'],
            [(string) $producto->id, '', '1500'],
        ]);
        $this->post(route('importacion.subir', 'productos'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'productos'), [
            'mapeo' => ['0' => 'id', '1' => 'nombre', '2' => 'precio_venta'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'productos'));
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'proveedor_id' => $proveedor->id, 'precio_venta' => 1500]);
    }

    // ── DNI y CUIT en columnas separadas mapeadas al mismo campo "CUIT" ──────────────

    public function test_clientes_dni_y_cuit_en_columnas_separadas_mapeadas_a_cuit(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'DNI', 'CUIT'],
            ['Cliente Con DNI', '30111222', ''],
            ['Cliente Con CUIT', '', '20111111112'],
            ['Cliente Sin Documento', '', ''],
            ['Cliente Ambos', '30111333', '20222222223'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'cuit', '2' => 'cuit'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));

        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con DNI', 'cuit' => '30111222', 'tipo_documento' => 'DNI']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con CUIT', 'cuit' => '20111111112', 'tipo_documento' => 'CUIT']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Sin Documento', 'cuit' => null]);
        // Ambas columnas con valor: gana DNI, se ignora el valor de CUIT de esa fila.
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Ambos', 'cuit' => '30111333', 'tipo_documento' => 'DNI']);
        $this->assertDatabaseCount('clientes', 4);
    }

    public function test_proveedores_dni_y_cuit_en_columnas_separadas_mapeadas_a_cuit(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Proveedor', 'DNI', 'CUIT'],
            ['Proveedor Con DNI', '30111222', ''],
            ['Proveedor Con CUIT', '', '20111111112'],
        ]);
        $this->post(route('importacion.subir', 'proveedores'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'proveedores'), [
            'mapeo' => ['0' => 'nombre', '1' => 'cuit', '2' => 'cuit'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'proveedores'));
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Proveedor Con DNI', 'cuit' => '30111222', 'tipo_documento' => 'DNI']);
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Proveedor Con CUIT', 'cuit' => '20111111112', 'tipo_documento' => 'CUIT']);
    }

    public function test_clientes_tercera_columna_a_cuit_sigue_bloqueada(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'DNI', 'CUIT', 'Otro Documento'],
            ['Cliente Uno', '30111222', '20111111112', '111'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'cuit', '2' => 'cuit', '3' => 'cuit'],
        ]);

        $response->assertRedirect(route('importacion.mapear', 'clientes'));
        $response->assertSessionHasErrors('mapeo');
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_clientes_dos_columnas_a_otro_campo_distinto_de_cuit_sigue_bloqueado(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Email 1', 'Email 2'],
            ['Cliente Uno', 'a@test.com', 'b@test.com'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'email', '2' => 'email'],
        ]);

        $response->assertRedirect(route('importacion.mapear', 'clientes'));
        $response->assertSessionHasErrors('mapeo');
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_clientes_cuit_con_guiones_se_normaliza_antes_de_validar(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'DNI', 'CUIT'],
            ['Cliente Con Guiones', '', '30-69323148-1'],
            ['Cliente Con DNI Puro', '30111222', ''],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'cuit', '2' => 'cuit'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con Guiones', 'cuit' => '30693231481', 'tipo_documento' => 'CUIT']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con DNI Puro', 'cuit' => '30111222', 'tipo_documento' => 'DNI']);
        $this->assertDatabaseCount('clientes', 2);
    }

    public function test_clientes_email_invalido_no_falla_la_fila_se_omite_como_advertencia(): void
    {
        Storage::fake('local');

        $archivo = $this->archivoXlsx([
            ['Nombre', 'Email'],
            ['Cliente Email Malo', 'mytques'],
            ['Cliente Email Bueno', 'ok@test.com'],
        ]);
        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $archivo]);

        $response = $this->post(route('importacion.confirmar', 'clientes'), [
            'mapeo' => ['0' => 'nombre', '1' => 'email'],
        ]);

        $response->assertRedirect(route('importacion.resumen', 'clientes'));
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Email Malo', 'email' => null]);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Email Bueno', 'email' => 'ok@test.com']);
        $this->assertDatabaseCount('clientes', 2);

        $resumen = $this->get(route('importacion.resumen', 'clientes'));
        $resumen->assertSee('no es válido, se omitió');
    }
}
