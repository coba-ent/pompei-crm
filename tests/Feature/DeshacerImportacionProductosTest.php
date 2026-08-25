<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Import\DeshacerImportacionService;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Spec 078 — snapshot y undo de una corrida de import de Productos & Servicios. */
class DeshacerImportacionProductosTest extends TestCase
{
    use RefreshDatabase;

    private function importador(): ImportadorFilas
    {
        return new ImportadorFilas(app(StockService::class));
    }

    private function servicioUndo(): DeshacerImportacionService
    {
        return new DeshacerImportacionService(app(StockService::class));
    }

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

    public function test_deshacer_corrida_restaura_precio_costo_y_stock(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $producto = Producto::create([
            'nombre' => 'Producto Original', 'codigo' => 'ORIG-1', 'tipo' => 'producto',
            'precio_venta' => 100, 'costo' => 50, 'activo' => true,
        ]);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);
        app(StockService::class)->ajustar($producto, null, $deposito, 10, 'Registro inicial', null);

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Codigo', 'Costo', "Precio {$lista->nombre}", 'Stock'],
            [$producto->id, 'Producto Original', 'ORIG-1', 999, 999, 999],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id', 1 => 'nombre', 2 => 'codigo', 3 => 'costo',
            4 => "precio_lista_{$lista->id}", 5 => "stock_deposito_{$deposito->id}",
        ], []);

        $producto->refresh();
        $this->assertEqualsWithDelta(999.0, (float) $producto->costo, 0.001);
        $this->assertEqualsWithDelta(999.0, (float) $producto->precios()->first()->precio, 0.001);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => 999]);

        $corrida = ImportacionCorrida::findOrFail($resultado['corrida_id']);
        $this->assertSame('vigente', $corrida->estado());

        $undo = $this->servicioUndo()->deshacer($corrida, null);

        $this->assertSame(1, $undo['revertidas']);
        $this->assertSame([], $undo['no_revertidas']);

        $producto->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $producto->costo, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $producto->precios()->first()->precio, 0.001);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $corrida->refresh();
        $this->assertSame('deshecho', $corrida->estado());
    }

    public function test_deshacer_alta_soft_deletea_producto_creado(): void
    {
        $ruta = $this->archivo([
            ['Nombre', 'Codigo'],
            ['Producto Nuevo', 'NUEVO-1'],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [0 => 'nombre', 1 => 'codigo'], []);

        $producto = Producto::where('codigo', 'NUEVO-1')->firstOrFail();
        $this->assertTrue($producto->activo);

        $corrida = ImportacionCorrida::findOrFail($resultado['corrida_id']);
        $undo = $this->servicioUndo()->deshacer($corrida, null);

        $this->assertSame(1, $undo['revertidas']);
        $producto->refresh();
        $this->assertFalse($producto->activo);
    }

    public function test_deshacer_parcial_fila_con_venta_posterior_queda_no_revertida(): void
    {
        $producto = Producto::create([
            'nombre' => 'Producto Con Venta', 'codigo' => 'VTA-1', 'tipo' => 'producto',
            'precio_venta' => 100, 'costo' => 50, 'activo' => true,
        ]);

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Codigo', 'Costo'],
            [$producto->id, 'Producto Con Venta', 'VTA-1', 777],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id', 1 => 'nombre', 2 => 'codigo', 3 => 'costo',
        ], []);

        $producto->refresh();
        $this->assertEqualsWithDelta(777.0, (float) $producto->costo, 0.001);

        // Actividad posterior al import: una venta sobre el mismo producto.
        $cliente = Cliente::create(['nombre' => 'Cliente Test']);
        $venta = Venta::create([
            'cliente_id' => $cliente->id, 'fecha_emision' => now()->toDateString(), 'tipo_comprobante' => 'B',
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'descripcion' => 'Venta posterior',
            'cantidad' => 1, 'precio_unitario' => 100, 'subtotal' => 100, 'subtotal_con_iva' => 121,
        ]);

        $corrida = ImportacionCorrida::findOrFail($resultado['corrida_id']);
        $undo = $this->servicioUndo()->deshacer($corrida, null);

        $this->assertSame(0, $undo['revertidas']);
        $this->assertCount(1, $undo['no_revertidas']);
        $this->assertSame($producto->id, $undo['no_revertidas'][0]['producto_id']);

        // No se pisó el cambio del import: el costo sigue siendo el importado, no el original.
        $producto->refresh();
        $this->assertEqualsWithDelta(777.0, (float) $producto->costo, 0.001);
    }

    public function test_corrida_ya_deshecha_no_se_puede_volver_a_deshacer(): void
    {
        $producto = Producto::create([
            'nombre' => 'Producto X', 'codigo' => 'X-1', 'tipo' => 'producto',
            'precio_venta' => 100, 'costo' => 50, 'activo' => true,
        ]);

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Codigo', 'Costo'],
            [$producto->id, 'Producto X', 'X-1', 60],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id', 1 => 'nombre', 2 => 'codigo', 3 => 'costo',
        ], []);

        $corrida = ImportacionCorrida::findOrFail($resultado['corrida_id']);
        $this->servicioUndo()->deshacer($corrida, null);

        $corrida->refresh();
        $this->assertFalse($corrida->puedeDeshacer());

        $this->expectException(\DomainException::class);
        $this->servicioUndo()->deshacer($corrida, null);
    }

    public function test_corrida_vencida_no_se_puede_deshacer(): void
    {
        $producto = Producto::create([
            'nombre' => 'Producto Y', 'codigo' => 'Y-1', 'tipo' => 'producto',
            'precio_venta' => 100, 'costo' => 50, 'activo' => true,
        ]);

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Codigo', 'Costo'],
            [$producto->id, 'Producto Y', 'Y-1', 60],
        ]);

        $resultado = $this->importador()->importar('productos', $ruta, [
            0 => 'id', 1 => 'nombre', 2 => 'codigo', 3 => 'costo',
        ], []);

        $corrida = ImportacionCorrida::findOrFail($resultado['corrida_id']);
        $corrida->update(['deshacer_disponible_hasta' => now()->subHour()]);

        $this->assertSame('vencido', $corrida->estado());
        $this->expectException(\DomainException::class);
        $this->servicioUndo()->deshacer($corrida, null);
    }
}
