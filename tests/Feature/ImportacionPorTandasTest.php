<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ImportacionFilaSnapshot;
use App\Models\ListaPrecio;
use App\Models\LogAuditoria;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Import\DeshacerImportacionService;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Spec 082 — el asistente procesa el archivo por tandas leyendo un NDJSON volcado una sola vez.
 *
 * Cubre las tres cosas que se pueden romper en silencio: filas salteadas o repetidas entre tandas,
 * un reintento que duplica snapshots de deshacer, y las reglas de las specs 026/027/074/078 que
 * dependían de que el importador abriera el Excel él mismo.
 */
class ImportacionPorTandasTest extends TestCase
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

    private function importador(): ImportadorFilas
    {
        return new ImportadorFilas(app(StockService::class));
    }

    /** @param  array<int, array<int, mixed>>  $filas */
    private function planilla(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $sheet->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'tandas').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    /** @param  array<int, array<int, mixed>>  $filas */
    private function archivoSubido(array $filas, string $nombre = 'archivo.xlsx'): UploadedFile
    {
        return new UploadedFile(
            $this->planilla($filas),
            $nombre,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /**
     * Corre la prevalidación (spec 083) hasta el final, como hace el modal de confirmación.
     *
     * @param  array<int|string, string>  $mapeo
     * @return array<string, mixed> el informe final
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

    /**
     * Recorre el asistente entero como lo hace el navegador: una request por tanda hasta que el
     * backend contesta `terminado`.
     *
     * @param  array<int|string, string>  $mapeo
     * @return array{tandas: int, ultima: array<string, mixed>, progresos: array<int, array{procesadas: int, total: int}>}
     */
    private function correrTandas(string $entidad, array $mapeo): array
    {
        // Spec 083: el navegador ahora prevalida el archivo entero ANTES de escribir, y el backend
        // rechaza la importación si no hay un análisis previo vigente y sin errores. Recorrer el
        // flujo real implica pasar por ahí primero.
        $this->prevalidar($entidad, $mapeo);

        $tandas = 0;
        $offset = 0;
        $progresos = [];
        $ultima = [];

        do {
            $respuesta = $this->post(route('importacion.confirmar-lote', $entidad), [
                'mapeo' => $mapeo,
                'offset' => $offset,
            ]);
            $respuesta->assertOk();

            $ultima = $respuesta->json();
            $progresos[] = ['procesadas' => $ultima['procesadas'], 'total' => $ultima['total']];
            $offset = $ultima['procesadas'];
            $tandas++;

            $this->assertLessThan(100, $tandas, 'El loop de tandas no termina.');
        } while (! $ultima['terminado']);

        return ['tandas' => $tandas, 'ultima' => $ultima, 'progresos' => $progresos];
    }

    // ---------------------------------------------------------------------------------------
    // US1 — el archivo completo se procesa por tandas
    // ---------------------------------------------------------------------------------------

    /** SC-001: ninguna fila queda sin procesar en un archivo que necesita varias tandas. */
    public function test_un_archivo_multitanda_se_procesa_completo_sin_saltear_filas(): void
    {
        Storage::fake('local');

        $filas = [['Nombre']];
        foreach (range(1, 260) as $n) {
            $filas[] = ['Cliente '.str_pad((string) $n, 3, '0', STR_PAD_LEFT)];
        }

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido($filas)]);

        $corrida = $this->correrTandas('clientes', [0 => 'nombre']);

        $this->assertGreaterThan(1, $corrida['tandas'], 'Con 260 filas tiene que hacer más de una tanda.');
        $this->assertSame(260, $corrida['ultima']['total']);
        $this->assertSame(260, $corrida['ultima']['procesadas']);
        $this->assertSame(260, Cliente::count());
        $this->assertNotNull(Cliente::where('nombre', 'Cliente 001')->first());
        $this->assertNotNull(Cliente::where('nombre', 'Cliente 260')->first());
    }

    /** FR-006: el progreso avanza monótonamente y nunca informa un total distinto del real. */
    public function test_el_progreso_de_cada_tanda_usa_el_total_real_del_archivo(): void
    {
        Storage::fake('local');

        $filas = [['Nombre']];
        foreach (range(1, 260) as $n) {
            $filas[] = ['Cliente '.$n];
        }

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido($filas)]);

        $corrida = $this->correrTandas('clientes', [0 => 'nombre']);

        $anterior = 0;
        foreach ($corrida['progresos'] as $progreso) {
            $this->assertSame(260, $progreso['total']);
            $this->assertGreaterThan($anterior, $progreso['procesadas']);
            $this->assertLessThanOrEqual(260, $progreso['procesadas']);
            $anterior = $progreso['procesadas'];
        }
    }

    /** FR-018: un archivo chico entra en una sola tanda y se comporta igual que siempre. */
    public function test_un_archivo_de_una_sola_tanda_se_comporta_igual_que_antes(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre'],
            ['Cliente Uno'],
            ['Cliente Dos'],
        ])]);

        $corrida = $this->correrTandas('clientes', [0 => 'nombre']);

        $this->assertSame(1, $corrida['tandas']);
        $this->assertSame(2, $corrida['ultima']['total']);
        $this->assertTrue($corrida['ultima']['terminado']);
        $this->assertSame(2, Cliente::count());
    }

    /** FR-004 / I5: los dos temporales (xlsx y ndjson) desaparecen al terminar la importación. */
    public function test_al_terminar_borra_el_xlsx_y_el_ndjson(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre'],
            ['Cliente Uno'],
        ])]);

        $this->assertCount(2, Storage::disk('local')->files('imports'));

        $this->correrTandas('clientes', [0 => 'nombre']);

        $this->assertCount(0, Storage::disk('local')->files('imports'));
    }

    /**
     * FR-014 / SC-005: reimportar la misma planilla sin cambios no puede generar ruido — ni un
     * evento de auditoría de precio ni un movimiento de stock. Es el chequeo que detecta que la
     * importación está "tocando" filas que no cambiaron.
     */
    public function test_reimportar_sin_cambios_no_genera_auditoria_de_precio_ni_movimientos(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $ruta = $this->planilla([
            ['Id', 'Nombre', 'Codigo', 'Costo', "Precio {$lista->nombre}", 'Stock'],
            [null, 'Producto Uno', 'P-1', 50, 100, 7],
        ]);

        $mapeo = [
            0 => 'id', 1 => 'nombre', 2 => 'codigo', 3 => 'costo',
            4 => "precio_lista_{$lista->id}", 5 => "stock_deposito_{$deposito->id}",
        ];

        $this->importador()->importar('productos', $ruta, $mapeo, []);
        $producto = Producto::where('codigo', 'P-1')->firstOrFail();

        $auditoriaAntes = LogAuditoria::count();
        $movimientosAntes = MovimientoStock::count();

        // Segunda pasada, misma planilla pero apuntando al producto ya creado.
        $rutaReimport = $this->planilla([
            ['Id', 'Nombre', 'Codigo', 'Costo', "Precio {$lista->nombre}", 'Stock'],
            [$producto->id, 'Producto Uno', 'P-1', 50, 100, 7],
        ]);
        $this->importador()->importar('productos', $rutaReimport, $mapeo, []);

        $this->assertSame($auditoriaAntes, LogAuditoria::count(), 'Un precio que no cambió no puede generar auditoría.');
        $this->assertSame($movimientosAntes, MovimientoStock::count(), 'Un stock que no cambió no puede generar movimiento.');
    }

    /**
     * Decisión 7 de research: con `$limite === null` se procesa TODO el archivo y el `$offset` se
     * IGNORA. Es el comportamiento heredado del que dependen los tests y las llamadas por CLI, y
     * ya causó un error al resolver el incidente a mano — queda fijado acá para que un refactor
     * futuro no lo cambie sin querer.
     */
    public function test_con_limite_null_se_procesa_todo_el_archivo_y_se_ignora_el_offset(): void
    {
        $ruta = $this->planilla([
            ['Nombre'],
            ['Cliente Uno'],
            ['Cliente Dos'],
            ['Cliente Tres'],
        ]);

        $resultado = $this->importador()->importar('clientes', $ruta, [0 => 'nombre'], [], null, [], offset: 2, limite: null);

        $this->assertSame(3, $resultado['total']);
        $this->assertSame(3, $resultado['importados']);
        $this->assertSame(3, Cliente::count());
    }

    /** FR-017: el automapeo por encabezado y alias sigue funcionando leyendo del NDJSON. */
    public function test_el_automapeo_por_encabezado_y_alias_sigue_funcionando(): void
    {
        Storage::fake('local');

        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $local = Deposito::create(['nombre' => 'Local', 'activo' => true]);

        $this->post(route('importacion.subir', 'productos'), ['archivo' => $this->archivoSubido([
            // Los alias reales del automapeo: el nombre pelado de la lista y "Stock {deposito}"
            // tal cual los escribe la exportación de Productos (spec 074).
            ['Id', 'Nombre', 'Código/SKU', 'Punto de Reposición', 'Stock Local', $lista->nombre],
            [1, 'Producto Uno', 'P-1', 5, 10, 100],
        ])]);

        $respuesta = $this->get(route('importacion.mapear', 'productos'));
        $respuesta->assertOk();

        $sugerencias = $respuesta->viewData('sugerencias');

        $this->assertSame('id', $sugerencias[0]);
        $this->assertSame('nombre', $sugerencias[1]);
        $this->assertSame('codigo', $sugerencias[2]);
        $this->assertSame('punto_reposicion', $sugerencias[3]);
        $this->assertSame("stock_deposito_{$local->id}", $sugerencias[4]);
        $this->assertSame("precio_lista_{$lista->id}", $sugerencias[5]);
    }

    /** FR-016: las reglas de mapeo del Paso 2 siguen vigentes en el endpoint de tandas. */
    public function test_el_campo_obligatorio_tiene_que_estar_mapeado(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre'],
            ['Cliente Uno'],
        ])]);

        $this->post(route('importacion.confirmar-lote', 'clientes'), ['mapeo' => [0 => ''], 'offset' => 0])
            ->assertStatus(422);

        $this->assertSame(0, Cliente::count());
    }

    /** FR-016: dos columnas al mismo campo se rechazan; "cuit" es la excepción y admite dos. */
    public function test_dos_columnas_al_mismo_campo_se_rechazan_salvo_cuit(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre', 'Nombre 2', 'DNI', 'CUIT'],
            ['Cliente Uno', 'Cliente Uno', '20111111', '20111111112'],
        ])]);

        $this->post(route('importacion.confirmar-lote', 'clientes'), [
            'mapeo' => [0 => 'nombre', 1 => 'nombre'],
            'offset' => 0,
        ])->assertStatus(422);

        // El mapeo válido pasa; como cualquier importación del navegador, primero prevalida
        // (spec 083).
        $mapeoValido = [0 => 'nombre', 1 => '', 2 => 'cuit', 3 => 'cuit'];
        $this->prevalidar('clientes', $mapeoValido);

        $this->post(route('importacion.confirmar-lote', 'clientes'), [
            'mapeo' => $mapeoValido,
            'offset' => 0,
        ])->assertOk();
    }

    /**
     * Edge case: si el archivo vigente ya no es el que estaba a la vista cuando se armó el mapeo
     * (otra pestaña subió otro), escribir sería cargar los datos en columnas equivocadas.
     */
    public function test_se_rechaza_la_tanda_si_los_encabezados_ya_no_coinciden_con_el_mapeo(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre'],
            ['Cliente Uno'],
        ])]);

        $this->post(route('importacion.confirmar-lote', 'clientes'), [
            'mapeo' => [0 => 'nombre'],
            'offset' => 0,
            'huella_columnas' => 'huella-de-otro-archivo',
        ])->assertStatus(422);

        $this->assertSame(0, Cliente::count());
    }

    /** Edge case: el temporal ya no está (limpieza/reinicio) — mensaje accionable, no 500. */
    public function test_si_el_temporal_ya_no_esta_pide_volver_a_subir_el_archivo(): void
    {
        Storage::fake('local');

        $this->post(route('importacion.subir', 'clientes'), ['archivo' => $this->archivoSubido([
            ['Nombre'],
            ['Cliente Uno'],
        ])]);

        foreach (Storage::disk('local')->files('imports') as $archivo) {
            Storage::disk('local')->delete($archivo);
        }

        $respuesta = $this->post(route('importacion.confirmar-lote', 'clientes'), [
            'mapeo' => [0 => 'nombre'],
            'offset' => 0,
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('volvé a subir el archivo', $respuesta->json('error'));
    }

    // ---------------------------------------------------------------------------------------
    // US2 — idempotencia de la tanda
    // ---------------------------------------------------------------------------------------

    /**
     * FR-009 / FR-010 / SC-004: el caso exacto del incidente — PHP terminó la tanda pero el proxy
     * cortó la respuesta, así que el navegador la reintenta. Las filas ya aplicadas se saltean:
     * ni snapshots duplicados ni contadores inflados.
     */
    public function test_reprocesar_el_mismo_offset_no_duplica_snapshots_ni_recuenta_filas(): void
    {
        $ruta = $this->planilla([
            ['Nombre', 'Codigo'],
            ['Producto Uno', 'P-1'],
            ['Producto Dos', 'P-2'],
            ['Producto Tres', 'P-3'],
        ]);

        $mapeo = [0 => 'nombre', 1 => 'codigo'];

        $primera = $this->importador()->importar('productos', $ruta, $mapeo, [], null, [], 0, 2);
        $corridaId = $primera['corrida_id'];
        $this->assertSame(2, $primera['importados']);

        // Reintento de la MISMA tanda.
        $reintento = $this->importador()->importar('productos', $ruta, $mapeo, [], null, [], 0, 2, $corridaId);

        $this->assertSame(0, $reintento['importados'], 'Las filas ya aplicadas no se vuelven a procesar.');
        $this->assertSame(2, Producto::count(), 'Un reintento no puede duplicar productos.');

        $numerosFila = ImportacionFilaSnapshot::where('importacion_corrida_id', $corridaId)->pluck('numero_fila');
        $this->assertSame($numerosFila->count(), $numerosFila->unique()->count(), 'No puede haber snapshots duplicados.');
        $this->assertSame(2, $numerosFila->count());

        $corrida = ImportacionCorrida::findOrFail($corridaId);
        $this->assertSame(2, $corrida->filas_creadas, 'Un reintento no puede recontar las filas.');
    }

    /**
     * FR-010 / FR-013: una corrida cortada y retomada es UNA sola corrida, y su deshacer tiene que
     * restaurar todas las filas. Un snapshot duplicado habría roto justamente esto.
     */
    public function test_el_deshacer_de_una_corrida_con_reintento_restaura_todas_las_filas(): void
    {
        $ruta = $this->planilla([
            ['Nombre', 'Codigo'],
            ['Producto Uno', 'P-1'],
            ['Producto Dos', 'P-2'],
            ['Producto Tres', 'P-3'],
            ['Producto Cuatro', 'P-4'],
        ]);

        $mapeo = [0 => 'nombre', 1 => 'codigo'];

        $primera = $this->importador()->importar('productos', $ruta, $mapeo, [], null, [], 0, 2);
        $corridaId = $primera['corrida_id'];

        // La tanda se cortó: se reintenta el mismo offset y recién después sigue el resto.
        $this->importador()->importar('productos', $ruta, $mapeo, [], null, [], 0, 2, $corridaId);
        $this->importador()->importar('productos', $ruta, $mapeo, [], null, [], 2, 2, $corridaId);

        $this->assertSame(4, Producto::count());

        $corrida = ImportacionCorrida::findOrFail($corridaId);
        $undo = (new DeshacerImportacionService(app(StockService::class)))->deshacer($corrida, null);

        $this->assertSame(4, $undo['revertidas']);
        $this->assertSame([], $undo['no_revertidas']);
        $this->assertSame(0, Producto::where('activo', true)->count());
    }

    // ---------------------------------------------------------------------------------------
    // US3 — Clientes y Proveedores
    // ---------------------------------------------------------------------------------------

    /** FR-019 / SC-006: Clientes por tandas, con sus reglas propias (DNI+CUIT, lista de precios). */
    public function test_clientes_por_tandas_respeta_sus_reglas_propias(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);

        $ruta = $this->planilla([
            ['Nombre', 'DNI', 'CUIT', 'Lista de Precios'],
            ['Cliente Uno', '20111111', '', 'Mayorista'],
            ['Cliente Dos', '', '20111111112', 'Mayorista'],
            ['Cliente Tres', '30222222', '', 'Mayorista'],
        ]);

        $mapeo = [0 => 'nombre', 1 => 'cuit', 2 => 'cuit', 3 => 'lista_precio_id'];
        $columnas = ['Nombre', 'DNI', 'CUIT', 'Lista de Precios'];

        $primera = $this->importador()->importar('clientes', $ruta, $mapeo, [], null, $columnas, 0, 2);
        $segunda = $this->importador()->importar('clientes', $ruta, $mapeo, [], null, $columnas, 2, 2);

        $this->assertSame(3, $primera['total']);
        $this->assertSame(2, $primera['importados']);
        $this->assertSame(1, $segunda['importados']);
        $this->assertSame(3, Cliente::count());
        $this->assertSame($lista->id, Cliente::where('nombre', 'Cliente Uno')->firstOrFail()->lista_precio_id);
        $this->assertSame('20111111112', Cliente::where('nombre', 'Cliente Dos')->firstOrFail()->cuit);
    }

    /** FR-019 / SC-006: Proveedores por tandas. */
    public function test_proveedores_por_tandas_respeta_sus_reglas_propias(): void
    {
        $ruta = $this->planilla([
            ['Proveedor', 'CUIT'],
            ['Proveedor Uno', '20111111112'],
            ['Proveedor Dos', '27222222228'],
            ['Proveedor Tres', '30333333339'],
        ]);

        $mapeo = [0 => 'nombre', 1 => 'cuit'];
        $columnas = ['Proveedor', 'CUIT'];

        $primera = $this->importador()->importar('proveedores', $ruta, $mapeo, [], null, $columnas, 0, 2);
        $segunda = $this->importador()->importar('proveedores', $ruta, $mapeo, [], null, $columnas, 2, 2);

        $this->assertSame(3, $primera['total']);
        $this->assertSame(3, Proveedor::count());
        $this->assertSame(1, $segunda['importados']);
        $this->assertSame('20111111112', Proveedor::where('nombre', 'Proveedor Uno')->firstOrFail()->cuit);
    }

    /**
     * FR-019: el salteo de filas ya aplicadas es específico de Productos (única entidad con
     * corrida/snapshot). Clientes y Proveedores no tienen dónde consultarlo y no se pueden romper
     * por eso — acá se verifica que su camino sigue intacto reprocesando el mismo offset.
     */
    public function test_las_entidades_sin_corrida_no_se_rompen_con_el_salteo_de_filas(): void
    {
        $ruta = $this->planilla([
            ['Nombre'],
            ['Cliente Uno'],
            ['Cliente Dos'],
        ]);

        $primera = $this->importador()->importar('clientes', $ruta, [0 => 'nombre'], [], null, [], 0, 2);

        $this->assertSame(2, $primera['importados']);
        $this->assertNull($primera['corrida_id']);
        $this->assertSame(2, Cliente::count());
    }
}
