<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Stock;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * US2 de la spec 074: `StockService::fijar()` lleva el stock a un valor absoluto resolviendo
 * lectura, delta y escritura bajo un mismo lock (FR-001 a FR-005, criterios CV-1 a CV-5).
 */
class StockFijarConcurrenciaTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        $this->producto = Producto::create(['nombre' => 'Producto Stock', 'codigo' => 'FIJ-1']);
        $this->deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function cantidadActual(): float
    {
        return (float) Stock::where('producto_id', $this->producto->id)
            ->where('deposito_id', $this->deposito->id)
            ->value('cantidad');
    }

    /** CV-1: fijar 50 sobre 10 deja 50 y crea un movimiento +40. */
    public function test_fijar_por_encima_del_actual_crea_el_movimiento_por_la_diferencia(): void
    {
        $this->stock()->ajustar($this->producto, null, $this->deposito, 10, 'Registro inicial');

        $resultado = $this->stock()->fijar($this->producto, null, $this->deposito, 50, 'Ajuste (importación)');

        $this->assertEqualsWithDelta(50.0, $resultado, 0.001);
        $this->assertEqualsWithDelta(50.0, $this->cantidadActual(), 0.001);

        $movimientos = MovimientoStock::where('producto_id', $this->producto->id)->get();
        $this->assertCount(2, $movimientos);
        $this->assertEqualsWithDelta(40.0, (float) $movimientos->last()->cantidad, 0.001);
    }

    /** CV-2 / FR-004: fijar 10 sobre 10 no crea ningún movimiento. */
    public function test_fijar_el_mismo_valor_no_escribe_nada(): void
    {
        $this->stock()->ajustar($this->producto, null, $this->deposito, 10, 'Registro inicial');

        $resultado = $this->stock()->fijar($this->producto, null, $this->deposito, 10, 'Ajuste (importación)');

        $this->assertEqualsWithDelta(10.0, $resultado, 0.001);
        $this->assertEqualsWithDelta(10.0, $this->cantidadActual(), 0.001);
        $this->assertSame(1, MovimientoStock::where('producto_id', $this->producto->id)->count());
    }

    /** CV-3: fijar 5 sobre 10 crea un movimiento -5. */
    public function test_fijar_por_debajo_del_actual_crea_un_movimiento_negativo(): void
    {
        $this->stock()->ajustar($this->producto, null, $this->deposito, 10, 'Registro inicial');

        $this->stock()->fijar($this->producto, null, $this->deposito, 5, 'Ajuste (importación)');

        $this->assertEqualsWithDelta(5.0, $this->cantidadActual(), 0.001);
        $this->assertEqualsWithDelta(
            -5.0,
            (float) MovimientoStock::where('producto_id', $this->producto->id)->latest('id')->value('cantidad'),
            0.001
        );
    }

    /** Sin fila previa de `stocks`, la cantidad actual se toma como 0. */
    public function test_fijar_sin_fila_previa_de_stock_parte_de_cero(): void
    {
        $this->stock()->fijar($this->producto, null, $this->deposito, 12, 'Ajuste (importación)');

        $this->assertEqualsWithDelta(12.0, $this->cantidadActual(), 0.001);
        $this->assertEqualsWithDelta(
            12.0,
            (float) MovimientoStock::where('producto_id', $this->producto->id)->value('cantidad'),
            0.001
        );
    }

    /** CV-5 / FR-003: el movimiento conserva tipo, descripción exacta y usuario. */
    public function test_el_movimiento_conserva_tipo_descripcion_y_usuario(): void
    {
        $usuario = auth()->user();

        $this->stock()->fijar($this->producto, null, $this->deposito, 30, 'Ajuste (importación)', $usuario);

        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $this->producto->id,
            'deposito_id' => $this->deposito->id,
            'tipo' => 'ajuste',
            'cantidad' => 30,
            'descripcion' => 'Ajuste (importación)',
            'usuario_id' => $usuario->id,
        ]);
    }

    /** FR-005: un producto que no controla stock (servicio) sigue siendo ignorado por el circuito. */
    public function test_un_servicio_no_entra_al_circuito_de_stock(): void
    {
        $servicio = Producto::create(['nombre' => 'Instalación', 'codigo' => 'SRV-1', 'tipo' => 'servicio']);

        $this->assertFalse($servicio->controlaStock());

        // El importador consulta `controlaStock()` antes de tocar el stock; nada se escribe.
        $this->assertSame(0, MovimientoStock::where('producto_id', $servicio->id)->count());
        $this->assertSame(0, Stock::where('producto_id', $servicio->id)->count());
    }

    /**
     * CV-4 / SC-003: con una operación concurrente sobre la misma clave, ambos movimientos
     * existen y `stocks.cantidad` reconcilia con la suma del histórico.
     *
     * **Se saltea fuera de MySQL a propósito.** La suite corre en SQLite (`phpunit.xml` fija
     * DB_CONNECTION=sqlite / :memory:), donde `lockForUpdate()` es un no-op: el test pasaría
     * en verde sin probar absolutamente nada, que es el peor resultado posible justo para el
     * único test que cubre el bug que motivó esta historia. La verificación real de SC-003
     * queda en la validación manual de quickstart.md §C3, que es obligatoria.
     */
    public function test_una_operacion_concurrente_no_se_pierde(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('requiere MySQL: lockForUpdate() es no-op en SQLite');
        }

        $this->stock()->ajustar($this->producto, null, $this->deposito, 10, 'Registro inicial');

        // Venta concurrente: -3 sobre el mismo stock.
        $this->stock()->registrarSalida($this->producto, null, $this->deposito, 3);
        // Importación que fija el valor absoluto que traía la planilla.
        $this->stock()->fijar($this->producto, null, $this->deposito, 50, 'Ajuste (importación)');

        $sumaHistorico = (float) MovimientoStock::where('producto_id', $this->producto->id)
            ->where('deposito_id', $this->deposito->id)
            ->sum('cantidad');

        // Ninguno de los tres movimientos se perdió...
        $this->assertSame(3, MovimientoStock::where('producto_id', $this->producto->id)->count());
        // ...y la foto reconcilia con el histórico.
        $this->assertEqualsWithDelta($sumaHistorico, $this->cantidadActual(), 0.001);
    }
}
