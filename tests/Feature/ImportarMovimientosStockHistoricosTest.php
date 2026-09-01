<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * El comando de carga histórica (spec 094).
 *
 * Los tests de este archivo protegen las garantías que el usuario pidió explícitamente: que el
 * stock actual no cambie, que no se dispare ninguna sincronización, y que todo se pueda deshacer.
 * Si alguno de estos se rompe, el daño no se ve en la base — se ve al día siguiente cuando el cron
 * publica stock histórico en Mercado Libre.
 */
class ImportarMovimientosStockHistoricosTest extends TestCase
{
    use RefreshDatabase;

    private string $directorio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directorio = sys_get_temp_dir().'/094-'.uniqid();
        mkdir($this->directorio);

        // El comando resuelve Local=5 y Full=6 por id, que es como están en producción. Se
        // insertan por query builder porque `id` no es fillable y Eloquent lo descartaría,
        // dejándolos con id 1 y 2: el insert de movimientos fallaría por foreign key.
        DB::table('depositos')->insert([
            ['id' => 5, 'nombre' => 'Local', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'nombre' => 'Full', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directorio.'/*') as $archivo) {
            unlink($archivo);
        }

        if (is_dir($this->directorio)) {
            rmdir($this->directorio);
        }

        parent::tearDown();
    }

    /** @param array<int, array<string, mixed>> $filas */
    private function informe(int $anio, array $filas): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();

        $hoja->fromArray(['Desde', 'Hasta', 'Unidades en Stock'], null, 'A1');
        $hoja->fromArray([
            'ID', 'Fecha', 'Usuario', 'Operación', 'Descripción', 'Tipo de Factura', 'N° de Factura',
            'Código', 'Producto', 'Tipo de Producto', 'Proveedor', 'Cantidad', 'Depósito', 'Saldo Stock',
        ], null, 'A4');

        $numero = 5;

        foreach ($filas as $fila) {
            $hoja->fromArray(array_values(array_merge([
                'id' => '-', 'fecha' => "6/15/{$anio}", 'usuario' => 'Info Pompei', 'operacion' => 'Aumento',
                'descripcion' => null, 'tipo_factura' => '-', 'nro' => '-', 'codigo' => 'SIN-CODIGO',
                'producto' => 'X', 'tipo_producto' => '-', 'proveedor' => '-', 'cantidad' => 1,
                'deposito' => 'Local', 'saldo' => 1,
            ], $fila)), null, "A{$numero}", true);
            $numero++;
        }

        (new Xlsx($libro))->save("{$this->directorio}/Informe Stock {$anio}.xlsx");
        $libro->disconnectWorksheets();
    }

    private function producto(string $codigo = '28379 TAPA'): Producto
    {
        return Producto::create(['nombre' => 'Tapa', 'tipo' => 'producto', 'codigo' => $codigo]);
    }

    private function correr(array $opciones = []): int
    {
        return $this->artisan('stock:importar-movimientos-historicos', array_merge([
            'directorio' => $this->directorio,
            '--anios' => '2024',
        ], $opciones))->run();
    }

    /** El dry-run es el default: sin `--escribir` no se toca la base (FR-017). */
    public function test_el_dry_run_es_el_default_y_no_escribe_nada(): void
    {
        $this->producto();
        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 3]]);

        $this->correr();

        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    public function test_con_escribir_carga_los_movimientos(): void
    {
        $producto = $this->producto();
        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 3, 'operacion' => 'Aumento']]);

        $this->correr(['--escribir' => true]);

        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id, 'tipo' => 'ajuste', 'cantidad' => 3, 'deposito_id' => 5,
        ]);
    }

    /**
     * LA GARANTÍA CENTRAL: el stock actual no se toca.
     *
     * `stocks` y `movimientos_stock` son tablas distintas y el comando sólo escribe en la segunda.
     * Este test convierte esa propiedad estructural en algo verificado.
     */
    public function test_no_altera_el_stock_actual(): void
    {
        $producto = $this->producto();
        DB::table('stocks')->insert([
            'producto_id' => $producto->id, 'deposito_id' => 5, 'cantidad' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->informe(2024, [
            ['codigo' => '28379 TAPA', 'cantidad' => -50, 'operacion' => 'Disminución'],
            ['codigo' => '28379 TAPA', 'cantidad' => 30, 'operacion' => 'Aumento'],
        ]);

        $this->correr(['--escribir' => true]);

        // (float) porque SQLite devuelve "7" y MySQL "7.000": lo que importa es el valor.
        $this->assertSame(7.0, (float) DB::table('stocks')
            ->where('producto_id', $producto->id)->value('cantidad'));
        $this->assertDatabaseCount('movimientos_stock', 2);
    }

    /**
     * EL RIESGO MÁS GRAVE DE LA SPEC.
     *
     * Insertando por Eloquent, cada `created` dispararía MovimientoStockObserver y marcaría las
     * publicaciones como pendientes; el cron después publicaría stock histórico en Mercado Libre.
     * El comando inserta por query builder justamente para que ese camino no exista.
     */
    public function test_no_marca_publicaciones_de_mercado_libre_como_pendientes(): void
    {
        $producto = $this->producto();

        DB::table('ml_publicacion_producto')->insert([
            'producto_id' => $producto->id, 'ml_item_id' => 'MLA123', 'stock_pendiente' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 5]]);

        $this->correr(['--escribir' => true]);

        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertSame(0, DB::table('ml_publicacion_producto')->where('stock_pendiente', true)->count());
    }

    /** Tampoco se dispara la rama de Tiendanube. */
    public function test_no_marca_variantes_de_tiendanube_como_pendientes(): void
    {
        $producto = $this->producto();

        DB::table('tn_variante_producto')->insert([
            'producto_id' => $producto->id, 'variant_id' => 1, 'tn_product_id' => 1,
            'vinculada_por' => 1, 'stock_pendiente' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 5]]);

        $this->correr(['--escribir' => true]);

        $this->assertSame(0, DB::table('tn_variante_producto')->where('stock_pendiente', true)->count());
    }

    /** No se generan eventos de auditoría: son 30.716 filas de ruido para una carga técnica. */
    public function test_no_genera_eventos_de_auditoria(): void
    {
        $this->producto();
        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 5]]);

        $this->correr(['--escribir' => true]);

        $this->assertSame(0, DB::table('logs_auditoria')->where('entidad_tipo', 'movimiento_stock')->count());
    }

    /**
     * EL CORTE. Si la operación ya tiene movimiento en el CRM, la fila se saltea (FR-006).
     *
     * Va por "ya tiene movimiento" y no por fecha porque hay compras cargadas con fecha retroactiva
     * al 06/08, anteriores al corte del 13/08: un corte por fecha las duplicaría.
     */
    public function test_saltea_las_operaciones_que_ya_tienen_movimiento_en_el_crm(): void
    {
        $producto = $this->producto();
        $venta = Venta::factory()->create();
        DB::table('ventas')->where('id', $venta->id)->update(['legacy_id' => '2024-FC-500']);

        DB::table('movimientos_stock')->insert([
            'producto_id' => $producto->id, 'deposito_id' => 5, 'tipo' => 'salida', 'cantidad' => -1,
            'origen_type' => Venta::class, 'origen_id' => $venta->id, 'fecha' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->informe(2024, [
            ['id' => 500, 'operacion' => 'Venta', 'codigo' => '28379 TAPA', 'cantidad' => -1],
        ]);

        $this->correr(['--escribir' => true]);

        // Sigue habiendo uno solo: el que el CRM ya tenía. La fila del Excel se salteó.
        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertSame(0, DB::table('movimientos_stock')->whereNotNull('carga_historica_id')->count());
    }

    /** Las filas sin operación se cortan por fecha, porque no hay contra qué comparar (FR-007). */
    public function test_saltea_los_ajustes_posteriores_al_corte_de_la_migracion(): void
    {
        $this->producto();
        $this->informe(2026, [
            ['fecha' => '8/20/2026', 'codigo' => '28379 TAPA', 'cantidad' => 5],
            ['fecha' => '8/1/2026', 'codigo' => '28379 TAPA', 'cantidad' => 3],
        ]);

        $this->correr(['--anios' => '2026', '--escribir' => true]);

        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertDatabaseHas('movimientos_stock', ['cantidad' => 3]);
    }

    /** Correrlo dos veces no duplica: la segunda encuentra las operaciones ya movidas (FR-019). */
    public function test_correrlo_dos_veces_no_duplica_los_movimientos_con_operacion(): void
    {
        $producto = $this->producto();
        $venta = Venta::factory()->create();
        DB::table('ventas')->where('id', $venta->id)->update(['legacy_id' => '2024-FC-500']);

        $this->informe(2024, [
            ['id' => 500, 'operacion' => 'Venta', 'codigo' => '28379 TAPA', 'cantidad' => -1],
        ]);

        $this->correr(['--escribir' => true]);
        $this->correr(['--escribir' => true]);

        $this->assertDatabaseCount('movimientos_stock', 1);
    }

    /** El deshacer borra exactamente lo de esa corrida y nada más (FR-018). */
    public function test_deshacer_borra_solo_lo_de_la_corrida(): void
    {
        $producto = $this->producto();

        DB::table('movimientos_stock')->insert([
            'producto_id' => $producto->id, 'deposito_id' => 5, 'tipo' => 'ajuste', 'cantidad' => 99,
            'fecha' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->informe(2024, [['codigo' => '28379 TAPA', 'cantidad' => 5]]);
        $this->correr(['--escribir' => true]);

        $corrida = DB::table('movimientos_stock')->whereNotNull('carga_historica_id')->value('carga_historica_id');

        $this->artisan('stock:importar-movimientos-historicos', [
            'directorio' => $this->directorio, '--deshacer' => $corrida,
        ])->expectsConfirmation(
            "Se van a borrar 1 movimientos de la corrida {$corrida}. ¿Seguir?", 'yes'
        )->run();

        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertDatabaseHas('movimientos_stock', ['cantidad' => 99, 'carga_historica_id' => null]);
    }

    /** Una operación que no está en el mapeo aborta: significa que el export cambió. */
    public function test_aborta_ante_una_operacion_desconocida(): void
    {
        $this->producto();
        $this->informe(2024, [['operacion' => 'Teletransportación', 'codigo' => '28379 TAPA', 'cantidad' => 1]]);

        $this->correr(['--escribir' => true]);

        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    /** El usuario del Excel es de Contagram: va en la descripción, no en `usuario_id` (FR-023). */
    public function test_el_usuario_queda_nulo_y_su_nombre_va_en_la_descripcion(): void
    {
        $this->producto();
        $this->informe(2024, [
            ['codigo' => '28379 TAPA', 'cantidad' => 5, 'usuario' => 'Juan Ignacio Conlon'],
        ]);

        $this->correr(['--escribir' => true]);

        $movimiento = DB::table('movimientos_stock')->first();

        $this->assertNull($movimiento->usuario_id);
        $this->assertStringContainsString('Juan Ignacio Conlon', $movimiento->descripcion);
    }
}
