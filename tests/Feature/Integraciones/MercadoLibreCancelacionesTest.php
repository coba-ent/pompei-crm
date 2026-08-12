<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US6 (spec 012): cancelaciones y reembolsos posteriores. FR-057/FR-058/FR-059.
 */
class MercadoLibreCancelacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);
    }

    private function ordenCancelada(string $id): array
    {
        return [
            'id' => $id, 'status' => 'cancelled',
            'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
            'total_amount' => 1210.0, 'currency_id' => 'ARS',
            'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'],
            'tags' => ['cancelled'],
            'order_items' => [[
                'item' => ['id' => 'MLA1', 'title' => 'Producto', 'variation_id' => null],
                'quantity' => 1, 'unit_price' => 1210.0,
            ]],
        ];
    }

    private function fakearSoloCanceladas(array $canceladas): void
    {
        Http::fake(function ($request) use ($canceladas) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => $canceladas, 'paging' => ['total' => count($canceladas), 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
        });
    }

    public function test_orden_convertida_que_se_cancela_no_modifica_la_venta_y_queda_senalada(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '7001', 'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.0, 'moneda' => 'ARS',
            'comprador_ml_id' => '999', 'comprador_apodo' => 'COMPRADOR', 'comprador_condicion_iva' => 'Consumidor Final',
            'sincronizada_en' => now(),
        ]);
        $orden->items()->create([
            'ml_item_id' => 'MLA1', 'titulo' => 'Producto', 'cantidad' => 1,
            'precio_unitario' => 1210.0, 'total_linea' => 1210.0, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $venta = $resultado['venta'];
        $stockAntes = (float) \App\Models\Stock::where('producto_id', $producto->id)->value('cantidad');

        $this->fakearSoloCanceladas([$this->ordenCancelada('7001')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        // spec 063: una orden CONVERTIDA que se cancela después ya no colapsa a "cancelada" sin
        // avisar — queda "requiere_atencion" con el motivo, para que alguien la revise (T007-T010).
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('orden_cancelada', $orden->motivo->value);
        $this->assertNotNull($orden->venta_id);

        // La Venta permanece intacta: no se borró, no cambió su total, el stock no se revirtió.
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
        $this->assertSame(1210.0, (float) $venta->fresh()->total);
        $this->assertSame($stockAntes, (float) \App\Models\Stock::where('producto_id', $producto->id)->value('cantidad'));
    }

    public function test_orden_no_convertida_que_se_cancela_deshabilita_la_conversion(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->fakearSoloCanceladas([$this->ordenCancelada('7002')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden = MercadoLibreOrden::where('ml_order_id', '7002')->firstOrFail();
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertFalse($orden->estado_conversion->habilitaCrearVenta());

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: false);
        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseCount('ventas', 0);
    }

    /** FR-012a: la búsqueda estándar del vendedor excluye canceladas; la segunda pasada las trae igual. */
    public function test_la_segunda_pasada_trae_las_ordenes_canceladas(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => [$this->ordenCancelada('7003')], 'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50]], 200);
            }

            // La pasada "normal" (sin order.status=cancelled) NO la trae — así se comporta la API real.
            return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
        });

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '7003', 'estado_conversion' => 'cancelada']);
    }
}
