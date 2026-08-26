<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\ImportacionFilaSnapshot;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Stock;
use App\Services\Import\ImportadorFilas;
use App\Services\Import\ValidadorFilasImportacion;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 083, US1 — el paso de revisión previo a escribir.
 *
 * Las dos cosas que tienen que ser ciertas para que el modal sirva de algo: que lo que informa sea
 * lo que realmente va a pasar (FR-003), y que informarlo no escriba nada (FR-002). Si cualquiera de
 * las dos se rompe, el modal miente — que es peor que no tenerlo.
 */
class PrevalidacionImportacionTest extends TestCase
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
    private function planilla(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $hoja->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'preval').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    /** @param  array<int, array<int, mixed>>  $filas */
    private function subir(string $entidad, array $filas): void
    {
        Storage::fake('local');

        $archivo = new UploadedFile(
            $this->planilla($filas),
            'archivo.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $this->post(route('importacion.subir', $entidad), ['archivo' => $archivo])->assertRedirect();
    }

    /**
     * Corre la prevalidación entera, como hace el modal.
     *
     * @param  array<int|string, string>  $mapeo
     * @return array<string, mixed>
     */
    private function prevalidar(string $entidad, array $mapeo): array
    {
        $offset = 0;

        do {
            $respuesta = $this->post(route('importacion.prevalidar', $entidad), [
                'mapeo' => $mapeo,
                'offset' => $offset,
            ]);
            $respuesta->assertOk();

            $cuerpo = $respuesta->json();
            $offset = $cuerpo['procesadas'];
        } while (! $cuerpo['terminado']);

        return $cuerpo['informe'];
    }

    /** @param  array<int|string, string>  $mapeo */
    private function confirmar(string $entidad, array $mapeo): TestResponse
    {
        return $this->post(route('importacion.confirmar-lote', $entidad), [
            'mapeo' => $mapeo,
            'offset' => 0,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // FR-003 / SC-003 — el veredicto coincide con lo que se aplica
    // -------------------------------------------------------------------------------------------

    /**
     * **El test que sostiene todo.** Si el validador y el importador se desincronizan, el modal
     * informa una cosa y la base termina con otra — el peor resultado posible de esta feature.
     */
    public function test_el_modo_previsto_coincide_fila_por_fila_con_el_aplicado(): void
    {
        $existente = Producto::factory()->create(['nombre' => 'Ya Existe']);

        $filas = [
            ['Id', 'Nombre', 'Costo'],
            [null, 'Alta Uno', 100],                     // alta
            [$existente->id, 'Actualizado', 200],        // actualización
            [null, '', 300],                             // error: falta el nombre
            [null, 'Alta Dos', 'no-numerico'],           // error: costo no numérico
            [999888, 'Alta Con Id Forzado', 50],         // alta preservando el id
        ];

        $ruta = $this->planilla($filas);
        $mapeo = [0 => 'id', 1 => 'nombre', 2 => 'costo'];
        $columnas = ['Id', 'Nombre', 'Costo'];

        $validador = new ValidadorFilasImportacion;
        $previstos = [];
        foreach (array_slice($filas, 1) as $i => $celdas) {
            $previstos[$i + 2] = $validador->evaluar($celdas, 'productos', $mapeo, [], $columnas)['modo'];
        }

        $resultado = (new ImportadorFilas(app(StockService::class)))
            ->importar('productos', $ruta, $mapeo, [], null, $columnas);

        $aplicados = [];
        foreach ($resultado['fallidos'] as $fallo) {
            $aplicados[$fallo['fila']] = 'error';
        }
        foreach (ImportacionFilaSnapshot::where('importacion_corrida_id', $resultado['corrida_id'])->get() as $snapshot) {
            $aplicados[$snapshot->numero_fila] = $snapshot->modo;
        }
        ksort($aplicados);

        $this->assertSame($previstos, $aplicados);
    }

    // -------------------------------------------------------------------------------------------
    // FR-001 / FR-002 — conteos antes de escribir, sin escribir
    // -------------------------------------------------------------------------------------------

    /** SC-001: los conteos de altas y actualizaciones se ven ANTES de escribir nada. */
    public function test_informa_altas_y_actualizaciones_de_un_archivo_mixto(): void
    {
        $existente = Cliente::factory()->create();

        $this->subir('clientes', [
            ['Id', 'Nombre'],
            [null, 'Cliente Nuevo Uno'],
            [null, 'Cliente Nuevo Dos'],
            [$existente->id, 'Cliente Renombrado'],
        ]);

        $informe = $this->prevalidar('clientes', [0 => 'id', 1 => 'nombre']);

        $this->assertSame(2, $informe['altas']);
        $this->assertSame(1, $informe['actualizaciones']);
        $this->assertSame(0, $informe['cantidad_errores']);
        $this->assertFalse($informe['hay_errores']);
    }

    /** FR-002: prevalidar no escribe. Ni una fila, ni un precio, ni un movimiento de stock. */
    public function test_prevalidar_no_escribe_absolutamente_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);
        $existente = Producto::factory()->create();

        $conteosAntes = [
            'productos' => Producto::count(),
            'clientes' => Cliente::count(),
            'proveedores' => Proveedor::count(),
            'precios' => PrecioProducto::count(),
            'stocks' => Stock::count(),
            'movimientos' => MovimientoStock::count(),
        ];

        $this->subir('productos', [
            ['Id', 'Nombre', 'Costo', "Precio {$lista->nombre}", 'Stock'],
            [null, 'Producto Nuevo', 10, 20, 5],
            [$existente->id, 'Producto Pisado', 30, 40, 9],
        ]);

        $this->prevalidar('productos', [
            0 => 'id', 1 => 'nombre', 2 => 'costo',
            3 => "precio_lista_{$lista->id}", 4 => "stock_deposito_{$deposito->id}",
        ]);

        $this->assertSame($conteosAntes, [
            'productos' => Producto::count(),
            'clientes' => Cliente::count(),
            'proveedores' => Proveedor::count(),
            'precios' => PrecioProducto::count(),
            'stocks' => Stock::count(),
            'movimientos' => MovimientoStock::count(),
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // FR-005 / FR-006 — el bloqueo
    // -------------------------------------------------------------------------------------------

    /** SC-002: con filas inválidas no se escribe NI UNA fila, aunque se llame al endpoint directo. */
    public function test_con_filas_invalidas_la_confirmacion_se_rechaza_y_no_escribe_nada(): void
    {
        $this->subir('clientes', [
            ['Nombre'],
            ['Cliente Bueno'],
            [''],
        ]);

        $informe = $this->prevalidar('clientes', [0 => 'nombre']);
        $this->assertTrue($informe['hay_errores']);

        $this->confirmar('clientes', [0 => 'nombre'])->assertStatus(422);

        $this->assertSame(0, Cliente::count());
    }

    /** El bloqueo no depende de la pantalla: sin prevalidación previa, tampoco se escribe. */
    public function test_confirmar_sin_prevalidacion_previa_se_rechaza(): void
    {
        $this->subir('clientes', [
            ['Nombre'],
            ['Cliente Bueno'],
        ]);

        $this->confirmar('clientes', [0 => 'nombre'])->assertStatus(422);

        $this->assertSame(0, Cliente::count());
    }

    /** FR-006: con cero errores la importación procede exactamente como antes. */
    public function test_un_archivo_totalmente_valido_deja_confirmar_y_termina_bien(): void
    {
        $this->subir('clientes', [
            ['Nombre'],
            ['Cliente Uno'],
            ['Cliente Dos'],
        ]);

        $informe = $this->prevalidar('clientes', [0 => 'nombre']);
        $this->assertFalse($informe['hay_errores']);

        $this->confirmar('clientes', [0 => 'nombre'])
            ->assertOk()
            ->assertJson(['terminado' => true]);

        $this->assertSame(2, Cliente::count());
    }

    /** Edge case: un archivo con encabezados y ninguna fila de datos da 0/0/0 y no rompe. */
    public function test_archivo_solo_con_encabezados_da_cero_sin_error(): void
    {
        $this->subir('clientes', [['Nombre']]);

        $informe = $this->prevalidar('clientes', [0 => 'nombre']);

        $this->assertSame(0, $informe['altas']);
        $this->assertSame(0, $informe['actualizaciones']);
        $this->assertSame(0, $informe['cantidad_errores']);
        $this->assertSame(0, $informe['total']);
    }

    /** Edge case: si ninguna fila sirve, se listan TODAS y la confirmación queda bloqueada. */
    public function test_archivo_sin_ninguna_fila_valida_lista_todos_los_errores(): void
    {
        $this->subir('clientes', [
            ['Nombre'],
            [''],
            [''],
            [''],
        ]);

        $informe = $this->prevalidar('clientes', [0 => 'nombre']);

        $this->assertSame(3, $informe['cantidad_errores']);
        $this->assertCount(3, $informe['errores']);
        $this->assertTrue($informe['hay_errores']);
    }

    /** FR-028: las tres solapas se comportan igual — Proveedores no es un caso aparte. */
    public function test_proveedores_prevalida_y_bloquea_igual_que_las_otras_solapas(): void
    {
        $this->subir('proveedores', [
            ['Nombre'],
            ['Proveedor Bueno'],
            [''],
        ]);

        $informe = $this->prevalidar('proveedores', [0 => 'nombre']);

        $this->assertSame(1, $informe['altas']);
        $this->assertTrue($informe['hay_errores']);

        $this->confirmar('proveedores', [0 => 'nombre'])->assertStatus(422);
        $this->assertSame(0, Proveedor::count());
    }

    // -------------------------------------------------------------------------------------------
    // FR-005b — campos afectados
    // -------------------------------------------------------------------------------------------

    /** El listado de campos es lo que evita descubrir DESPUÉS que se pisaron precios o stock. */
    public function test_campos_afectados_lista_los_campos_mapeados_con_valor_y_su_cantidad(): void
    {
        $unoObj = Producto::factory()->create();
        $dosObj = Producto::factory()->create();

        $this->subir('productos', [
            ['Id', 'Nombre', 'Costo', 'Descripcion'],
            [$unoObj->id, 'Uno', 100, 'con descripcion'],
            [$dosObj->id, 'Dos', 200, ''],
        ]);

        $informe = $this->prevalidar('productos', [0 => 'id', 1 => 'nombre', 2 => 'costo', 3 => 'descripcion']);

        $this->assertSame(2, $informe['campos_afectados']['Nombre']);
        $this->assertSame(2, $informe['campos_afectados']['Costo']);
        $this->assertSame(1, $informe['campos_afectados']['Descripción']);
        // "Id" no se escribe como campo de negocio: no puede figurar como campo afectado.
        $this->assertArrayNotHasKey('Id', $informe['campos_afectados']);
    }

    // -------------------------------------------------------------------------------------------
    // FR-009 — huella
    // -------------------------------------------------------------------------------------------

    /** Confirmar con un mapeo distinto del prevalidado se rechaza: el informe aprobado ya no aplica. */
    public function test_confirmar_con_un_mapeo_distinto_del_prevalidado_se_rechaza(): void
    {
        $this->subir('clientes', [
            ['Nombre', 'Telefono'],
            ['Cliente Uno', '1122334455'],
        ]);

        $this->prevalidar('clientes', [0 => 'nombre']);

        $this->confirmar('clientes', [0 => 'nombre', 1 => 'telefono'])->assertStatus(422);

        $this->assertSame(0, Cliente::count());
    }

    // -------------------------------------------------------------------------------------------
    // FR-018 / FR-019 / FR-020 — mensajes
    // -------------------------------------------------------------------------------------------

    /** FR-019: el motivo nombra la columna como la ve el usuario, no como se llama por dentro. */
    public function test_el_motivo_nombra_la_columna_del_archivo_y_no_el_campo_interno(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'AHORA 3', 'activo' => true]);

        $this->subir('productos', [
            ['Nombre', 'AHORA 3'],
            ['Producto Uno', 'no-es-un-numero'],
        ]);

        $informe = $this->prevalidar('productos', [0 => 'nombre', 1 => "precio_lista_{$lista->id}"]);

        $motivo = implode(' ', $informe['errores'][0]['motivos']);
        $this->assertStringContainsString('AHORA 3', $motivo);
        $this->assertStringNotContainsString('precio_lista_', $motivo);
    }

    /** FR-018 / SC-006: ni una palabra en inglés ni un nombre interno en ningún motivo. */
    public function test_ningun_motivo_tiene_ingles_ni_guiones_bajos(): void
    {
        $this->subir('productos', [
            ['Id', 'Nombre', 'Costo', 'Punto de Reposición'],
            ['abc', 'Producto Uno', 10, 5],        // Id no numérico
            [null, '', 20, 5],                     // falta el nombre
            [null, 'Producto Tres', 'texto', 5],   // costo no numérico
            [null, 'Producto Cuatro', 30, 'x'],    // punto de reposición no numérico
        ]);

        $informe = $this->prevalidar('productos', [0 => 'id', 1 => 'nombre', 2 => 'costo', 3 => 'punto_reposicion']);

        $this->assertGreaterThan(0, $informe['cantidad_errores']);

        foreach ($informe['errores'] as $error) {
            foreach ($error['motivos'] as $motivo) {
                foreach (['The ', ' field ', ' must be ', ' is required', '_'] as $prohibido) {
                    $this->assertStringNotContainsString(
                        $prohibido,
                        $motivo,
                        "El motivo \"{$motivo}\" tiene texto en inglés o un nombre interno."
                    );
                }
            }
        }
    }

    /** FR-020: una fila con tres problemas los informa a los tres, no de a uno por intento. */
    public function test_una_fila_con_tres_problemas_informa_los_tres(): void
    {
        $this->subir('productos', [
            ['Nombre', 'Costo', 'Precio de Venta', 'Punto de Reposición'],
            ['', 'texto', 'texto', 'texto'],
        ]);

        $informe = $this->prevalidar('productos', [
            0 => 'nombre', 1 => 'costo', 2 => 'precio_venta', 3 => 'punto_reposicion',
        ]);

        $this->assertCount(1, $informe['errores']);
        $this->assertGreaterThanOrEqual(3, count($informe['errores'][0]['motivos']));
    }
}
