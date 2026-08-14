<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStockFull;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 065 · US4 — reflejo ML → CRM de la existencia del centro de distribución de
 * Mercado Libre. **Es la parte de más riesgo de la feature: escribe movimientos de
 * stock reales**, así que estos tests se escribieron antes que el servicio
 * (principio IV de la constitución).
 */
class MercadoLibreStockFullTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $general;

    private Deposito $depositoFull;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        $this->general = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->depositoFull = Deposito::create(['nombre' => 'Mercado Libre Full', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
            'deposito_id' => $this->general->id,
            'deposito_full_id' => $this->depositoFull->id,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
    }

    private function vinculoFull(string $mlItemId, string $inventoryId, ?Producto $producto = null): MercadoLibrePublicacionProducto
    {
        $producto ??= Producto::factory()->create(['tipo' => 'producto']);

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $mlItemId,
            'producto_id' => $producto->id,
            'logistic_type' => 'fulfillment',
            'inventory_id' => $inventoryId,
        ]);
    }

    /** Respuesta real del contrato: `available_quantity` es lo vendible, `not_available_quantity` no. */
    private function fakeInventarios(array $porInventario): void
    {
        Http::fake(collect($porInventario)->mapWithKeys(fn (array $datos, string $inventario) => [
            "api.mercadolibre.com/inventories/{$inventario}/stock/fulfillment" => Http::response(array_merge([
                'inventory_id' => $inventario,
                'total' => $datos['available_quantity'],
                'not_available_quantity' => 0,
                'not_available_detail' => [],
            ], $datos), 200),
        ])->all());
    }

    private function disponibilidad(Producto $producto, Deposito $deposito): float
    {
        return app(StockService::class)->disponibilidad($producto->fresh(), null, $deposito);
    }

    /** T022 · FR-009: la existencia del depósito Full queda igual a la que informa Mercado Libre. */
    public function test_refleja_la_existencia_informada_por_mercado_libre(): void
    {
        $vinculo = $this->vinculoFull('MLA762900978', 'TPCW64194');
        $this->fakeInventarios(['TPCW64194' => ['available_quantity' => 4]]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
    }

    /** T022 · FR-009: sólo se computa lo vendible; lo dañado o en tránsito no es despachable. */
    public function test_no_computa_la_existencia_no_vendible(): void
    {
        $vinculo = $this->vinculoFull('MLA1', 'INV1');
        $this->fakeInventarios(['INV1' => [
            'available_quantity' => 4, 'total' => 9, 'not_available_quantity' => 5,
        ]]);

        app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
    }

    /** T022 · FR-012: idempotencia — si no cambió nada, no se genera ningún movimiento. */
    public function test_una_segunda_corrida_sin_cambios_no_genera_movimientos(): void
    {
        $vinculo = $this->vinculoFull('MLA1', 'INV1');
        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        app(SincronizadorStockFull::class)->ejecutar();
        $movimientosTrasLaPrimera = MovimientoStock::count();

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame($movimientosTrasLaPrimera, MovimientoStock::count());
        $this->assertSame(0, $resultado['actualizados']);
        $this->assertSame(1, $resultado['sin_cambios']);
        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
    }

    /** T022 · FR-011: el reflejo no toca ningún otro depósito. */
    public function test_solo_cambia_el_deposito_full(): void
    {
        $vinculo = $this->vinculoFull('MLA1', 'INV1');
        app(StockService::class)->ajustar($vinculo->producto, null, $this->general, 7, 'carga inicial');

        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
        $this->assertSame(7.0, $this->disponibilidad($vinculo->producto, $this->general));
    }

    /** T022 · FR-009a: recorre TODOS los Full, no sólo los marcados pendientes. */
    public function test_recorre_todos_los_full_aunque_no_esten_pendientes(): void
    {
        $uno = $this->vinculoFull('MLA1', 'INV1');
        $dos = $this->vinculoFull('MLA2', 'INV2');
        MercadoLibrePublicacionProducto::query()->update(['stock_pendiente' => false]);

        $this->fakeInventarios([
            'INV1' => ['available_quantity' => 4],
            'INV2' => ['available_quantity' => 9],
        ]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(2, $resultado['actualizados']);
        $this->assertSame(4.0, $this->disponibilidad($uno->producto, $this->depositoFull));
        $this->assertSame(9.0, $this->disponibilidad($dos->producto, $this->depositoFull));
    }

    /** T022 · FR-009c: el reflejo NUNCA escribe hacia Mercado Libre. */
    public function test_nunca_escribe_hacia_mercado_libre(): void
    {
        $this->vinculoFull('MLA1', 'INV1');
        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        app(SincronizadorStockFull::class)->ejecutar();

        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    /** T027: las llamadas quedan en el historial como operaciones de lectura. */
    public function test_registra_las_llamadas_como_lectura(): void
    {
        $this->vinculoFull('MLA1', 'INV1');
        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        app(SincronizadorStockFull::class)->ejecutar();

        $this->assertTrue(
            MercadoLibreOperacionLog::where('operacion', 'reflejar_stock_full')
                ->where('sentido', 'lectura')->exists()
        );
    }

    // ── T023: casos borde ─────────────────────────────────────────────────────

    /** FR-009b: dos publicaciones que comparten inventario computan una sola vez. */
    public function test_deduplica_por_inventario_compartido(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $this->vinculoFull('MLA1', 'INV1', $producto);
        $this->vinculoFull('MLA2', 'INV1', $producto);

        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        // Una sola llamada y una sola imputación: 4, nunca 8.
        Http::assertSentCount(1);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(4.0, $this->disponibilidad($producto, $this->depositoFull));
    }

    /**
     * FR-014c: un inventario compartido por productos DISTINTOS no se refleja y se reporta.
     * Imputarlo a cualquiera de los dos sería inventar un dato: no hay forma de saber
     * cuántas unidades corresponden a cada producto.
     */
    public function test_inventario_compartido_por_productos_distintos_no_se_refleja(): void
    {
        $unVinculo = $this->vinculoFull('MLA1', 'INV1');
        $otroVinculo = $this->vinculoFull('MLA2', 'INV1');

        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(0, $resultado['actualizados']);
        $this->assertSame(1, $resultado['conflictos']);
        $this->assertStringContainsString('INV1', $resultado['mensaje']);
        $this->assertSame(0.0, $this->disponibilidad($unVinculo->producto, $this->depositoFull));
        $this->assertSame(0.0, $this->disponibilidad($otroVinculo->producto, $this->depositoFull));
    }

    /** FR-014b: un vínculo cuyo producto ya no existe se saltea sin romper la corrida. */
    public function test_vinculo_con_producto_inexistente_se_saltea_sin_error(): void
    {
        $huerfano = $this->vinculoFull('MLA1', 'INV1');
        $sano = $this->vinculoFull('MLA2', 'INV2');
        $huerfano->producto->delete();

        $this->fakeInventarios([
            'INV1' => ['available_quantity' => 4],
            'INV2' => ['available_quantity' => 9],
        ]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(9.0, $this->disponibilidad($sano->producto, $this->depositoFull));
    }

    /** FR-014: sin depósito Full configurado no refleja nada, pero avisa sin abortar. */
    public function test_sin_deposito_configurado_avisa_sin_abortar(): void
    {
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => null]);
        $this->vinculoFull('MLA1', 'INV1');
        Http::fake();

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertSame('sin_deposito', $resultado['tipo']);
        $this->assertStringContainsString('no hay depósito para publicaciones Full configurado', $resultado['mensaje']);
        Http::assertNothingSent();
    }

    /**
     * FR-014a: el modo sólo lectura NO frena el reflejo. Es un kill-switch de **escrituras
     * hacia Mercado Libre**, y esto es exactamente lo contrario: una lectura.
     */
    public function test_el_modo_solo_lectura_no_bloquea_el_reflejo(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        $vinculo = $this->vinculoFull('MLA1', 'INV1');
        $this->fakeInventarios(['INV1' => ['available_quantity' => 4]]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
    }

    /** La función desactivada sí corta: es el interruptor general de la integración. */
    public function test_la_funcion_desactivada_corta_el_reflejo(): void
    {
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);
        $this->vinculoFull('MLA1', 'INV1');
        Http::fake();

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        Http::assertNothingSent();
    }

    /** Ante fallo de un inventario se conserva la existencia actual y no se pone en cero. */
    public function test_falla_de_un_inventario_no_pone_el_stock_en_cero(): void
    {
        $vinculo = $this->vinculoFull('MLA1', 'INV1');
        app(StockService::class)->ajustar($vinculo->producto, null, $this->depositoFull, 4, 'reflejo previo');

        Http::fake(['api.mercadolibre.com/inventories/*' => Http::response([], 500)]);

        $resultado = app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(1, $resultado['con_error']);
        $this->assertSame(4.0, $this->disponibilidad($vinculo->producto, $this->depositoFull));
    }

    /**
     * T024 · quickstart §Escenario 4: un producto con una publicación Full (4 u.) y otra de
     * logística propia (3 u.) queda 4 en el depósito Full y 3 en el general. Nunca 7 juntas
     * ni 4 en ambos — es el caso que motivó toda la feature.
     */
    public function test_caso_real_full_y_logistica_propia_del_mismo_producto(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $this->vinculoFull('MLA762900978', 'TPCW64194', $producto);
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA111', 'producto_id' => $producto->id, 'logistic_type' => 'xd_drop_off',
        ]);

        app(StockService::class)->ajustar($producto, null, $this->general, 3, 'stock del domicilio');
        $this->fakeInventarios(['TPCW64194' => ['available_quantity' => 4]]);

        app(SincronizadorStockFull::class)->ejecutar();

        $this->assertSame(4.0, $this->disponibilidad($producto, $this->depositoFull));
        $this->assertSame(3.0, $this->disponibilidad($producto, $this->general));
    }
}
