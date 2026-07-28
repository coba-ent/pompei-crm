<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US5 (spec 012): creación automática de ventas. FR-051/FR-052/FR-053/FR-055,
 * FR-056, SC-005.
 */
class MercadoLibreCreacionAutomaticaTest extends TestCase
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

    private function ordenCruda(int $id, string $mlItemId = 'MLA1'): array
    {
        return [
            'id' => $id, 'status' => 'paid',
            'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
            'total_amount' => 1210.0, 'currency_id' => 'ARS',
            'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'.$id],
            'tags' => ['paid'],
            'order_items' => [[
                'item' => ['id' => $mlItemId, 'title' => 'Producto', 'variation_id' => null],
                'quantity' => 1, 'unit_price' => 1210.0,
            ]],
        ];
    }

    private function fakearBusqueda(array $normales): void
    {
        Http::fake(function ($request) use ($normales) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => $normales, 'paging' => ['total' => count($normales), 'offset' => 0, 'limit' => 50]], 200);
        });
    }

    public function test_orden_resoluble_se_convierte_sola_con_la_creacion_automatica_activa(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->fakearBusqueda([$this->ordenCruda(1001)]);

        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '1001', 'estado_conversion' => 'convertida', 'creacion_automatica' => true]);
    }

    public function test_orden_sin_vincular_no_crea_venta_ni_mueve_stock_y_queda_requiere_atencion(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);

        $this->fakearBusqueda([$this->ordenCruda(1002, 'MLA-SIN-VINCULAR')]);

        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseHas('ml_ordenes', [
            'ml_order_id' => '1002', 'estado_conversion' => 'requiere_atencion', 'motivo' => 'publicacion_sin_vincular',
        ]);
    }

    public function test_resolver_el_motivo_vuelve_a_dejar_la_orden_convertible(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => false]);

        $this->fakearBusqueda([$this->ordenCruda(1003, 'MLA-PENDIENTE')]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '1003', 'estado_conversion' => 'requiere_atencion']);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-PENDIENTE', 'producto_id' => $producto->id]);

        $this->fakearBusqueda([$this->ordenCruda(1003, 'MLA-PENDIENTE')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '1003', 'estado_conversion' => 'lista']);
    }

    public function test_interruptor_apagado_no_crea_ninguna_venta(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => false]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->fakearBusqueda([$this->ordenCruda(1004)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '1004', 'estado_conversion' => 'lista']);
    }

    /** T082 — un fallo a mitad de camino no deja Venta, cobranza ni movimiento de stock huérfanos. */
    public function test_fallo_durante_la_conversion_automatica_no_deja_venta_parcial(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);
        // Sin cuenta "Mercado Pago" visible → ConversorOrdenAVenta rechaza antes de crear nada.
        CuentaTesoreria::where('nombre', 'Mercado Pago')->update(['visible' => false]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->fakearBusqueda([$this->ordenCruda(1005)]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('cobros', 0);
        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseHas('ml_ordenes', ['ml_order_id' => '1005']);
    }
}
