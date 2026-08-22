<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Red de no-regresión del importador de Productos (spec 074, T003).
 *
 * Fija el comportamiento **actual** de `ImportadorFilas` sobre productos —alta,
 * actualización por Id, tolerancia a filas inválidas y advertencia de "Stock
 * Total no coincide"— **antes** de que US1 (auditoría de precios) y US2 (stock
 * atómico) lo modifiquen. Cualquier regresión introducida por esas historias
 * tiene que saltar acá (FR-015, FR-016).
 */
class ImportacionProductosStockTest extends TestCase
{
    use RefreshDatabase;

    private function importador(): ImportadorFilas
    {
        return new ImportadorFilas(app(StockService::class));
    }

    /** Arma un .xlsx temporal a partir de una matriz de filas (fila 0 = encabezados). */
    private function archivo(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $sheet->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_alta_por_importacion_crea_producto_con_precio_y_stock(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $ruta = $this->archivo([
            ['Nombre', 'Codigo', 'Precio', 'Stock'],
            ['Producto Alta', 'ALTA-1', 150, 7],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
            2 => "precio_lista_{$lista->id}",
            3 => "stock_deposito_{$deposito->id}",
        ], []);

        $this->assertSame(1, $resultado['importados']);
        $this->assertSame([], $resultado['fallidos']);

        $producto = Producto::where('codigo', 'ALTA-1')->firstOrFail();
        $this->assertEqualsWithDelta(150.0, (float) $producto->precios()->first()->precio, 0.001);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => 7,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'tipo' => 'ajuste',
            'cantidad' => 7,
            'descripcion' => 'Registro inicial (importación)',
        ]);
    }

    public function test_actualizacion_por_id_ajusta_precio_y_stock_contra_el_valor_actual(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $producto = Producto::create(['nombre' => 'Producto Base', 'codigo' => 'UPD-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);
        app(StockService::class)->ajustar($producto, null, $deposito, 10, 'Registro inicial');

        // La planilla trae el valor FINAL deseado (10 -> 25), no un delta.
        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Precio', 'Stock'],
            [$producto->id, 'Producto Base', 180, 25],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "precio_lista_{$lista->id}",
            3 => "stock_deposito_{$deposito->id}",
        ], []);

        $this->assertSame(1, $resultado['importados']);

        // Upsert de precio: sigue habiendo una sola fila en esa lista, con el valor nuevo.
        $this->assertSame(1, $producto->precios()->count());
        $this->assertEqualsWithDelta(180.0, (float) $producto->precios()->first()->precio, 0.001);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => 25,
        ]);
        // El ajuste es por diferencia (25 - 10 = 15), no por el valor absoluto.
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'tipo' => 'ajuste',
            'cantidad' => 15,
            'descripcion' => 'Ajuste (importación)',
        ]);
    }

    public function test_stock_sin_diferencia_no_genera_movimiento(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $producto = Producto::create(['nombre' => 'Producto Igual', 'codigo' => 'IGUAL-1']);
        app(StockService::class)->ajustar($producto, null, $deposito, 10, 'Registro inicial');

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Stock'],
            [$producto->id, 'Producto Igual', 10],
        ]);

        $this->importador()->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "stock_deposito_{$deposito->id}",
        ], []);

        // Sólo el movimiento del registro inicial: la importación no agregó ninguno.
        $this->assertSame(1, MovimientoStock::where('producto_id', $producto->id)->count());
    }

    public function test_fila_invalida_no_aborta_el_archivo(): void
    {
        $ruta = $this->archivo([
            ['Nombre', 'Codigo'],
            ['', 'SIN-NOMBRE'],      // inválida: nombre es obligatorio
            ['Producto Valido', 'OK-1'],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
        ], []);

        $this->assertSame(1, $resultado['importados']);
        $this->assertCount(1, $resultado['fallidos']);
        $this->assertSame(2, $resultado['fallidos'][0]['fila']);
        $this->assertDatabaseHas('productos', ['codigo' => 'OK-1']);
        $this->assertDatabaseMissing('productos', ['codigo' => 'SIN-NOMBRE']);
    }

    /**
     * Round-trip de la exportación (spec 074): el stock NEGATIVO tiene que poder reimportarse.
     * Es el estado real de un producto sobrevendido, la exportación lo escribe tal cual y
     * `StockService` lo permite — con el viejo `min:0` la fila entera se caía.
     */
    public function test_el_stock_negativo_se_puede_importar(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $producto = Producto::create(['nombre' => 'Sobrevendido', 'codigo' => 'NEG-1']);
        app(StockService::class)->ajustar($producto, null, $deposito, 2, 'Registro inicial');

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Stock'],
            [$producto->id, 'Sobrevendido', -4],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "stock_deposito_{$deposito->id}",
        ], []);

        $this->assertSame(1, $resultado['importados']);
        $this->assertSame([], $resultado['fallidos']);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => -4,
        ]);
        // 2 -> -4 son 6 unidades menos.
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'cantidad' => -6,
            'descripcion' => 'Ajuste (importación)',
        ]);
    }

    /** El alta también respeta el stock negativo (antes se salteaba junto con el cero). */
    public function test_el_alta_con_stock_negativo_lo_registra(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $ruta = $this->archivo([
            ['Nombre', 'Codigo', 'Stock'],
            ['Alta Negativa', 'NEG-ALTA', -3],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
            2 => "stock_deposito_{$deposito->id}",
        ], []);

        $this->assertSame(1, $resultado['importados']);

        $producto = Producto::where('codigo', 'NEG-ALTA')->firstOrFail();
        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => -3,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'cantidad' => -3,
            'descripcion' => 'Registro inicial (importación)',
        ]);
    }

    /** Un precio negativo sí sigue siendo inválido: el `min:0` sólo se relajó para el stock. */
    public function test_el_precio_negativo_sigue_rechazandose(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);

        $ruta = $this->archivo([
            ['Nombre', 'Codigo', 'Precio'],
            ['Precio Negativo', 'NEG-PRECIO', -100],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
            2 => "precio_lista_{$lista->id}",
        ], []);

        $this->assertSame(0, $resultado['importados']);
        $this->assertCount(1, $resultado['fallidos']);
        $this->assertDatabaseMissing('productos', ['codigo' => 'NEG-PRECIO']);
    }

    /** Stock cero sigue sin generar movimiento en el alta (no hay nada que registrar). */
    public function test_el_alta_con_stock_cero_no_genera_movimiento(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $ruta = $this->archivo([
            ['Nombre', 'Codigo', 'Stock'],
            ['Alta Cero', 'CERO-1', 0],
        ]);

        $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
            2 => "stock_deposito_{$deposito->id}",
        ], []);

        $producto = Producto::where('codigo', 'CERO-1')->firstOrFail();
        $this->assertSame(0, MovimientoStock::where('producto_id', $producto->id)->count());
    }

    public function test_stock_total_que_no_coincide_genera_advertencia_pero_importa(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $ruta = $this->archivo([
            ['Nombre', 'Codigo', 'Stock Central', 'Stock Total'],
            ['Producto Desfasado', 'ADV-1', 5, 9],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'nombre',
            1 => 'codigo',
            2 => "stock_deposito_{$deposito->id}",
            3 => 'stock_total_verificacion',
        ], []);

        $this->assertSame(1, $resultado['importados']);
        $this->assertCount(1, $resultado['advertencias']);
        $this->assertStringContainsString('Stock Total', $resultado['advertencias'][0]['motivo']);

        // La advertencia no impide la importación ni altera el stock por depósito.
        $producto = Producto::where('codigo', 'ADV-1')->firstOrFail();
        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => 5,
        ]);
    }
}
