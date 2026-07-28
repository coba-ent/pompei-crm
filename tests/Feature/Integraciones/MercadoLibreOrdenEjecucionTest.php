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
use App\Services\Stock\StockService;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 013, FR-006, research.md R4): orden de ejecución CRM→ML después de
 * ML→CRM. Ejecutar `mercadolibre:sincronizar-ordenes` y luego
 * `mercadolibre:sincronizar-stock` en el mismo tick debe empujar el stock ya
 * neto de la orden recién traída, sin necesitar una segunda corrida.
 */
class MercadoLibreOrdenEjecucionTest extends TestCase
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
            'creacion_automatica' => true,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);
    }

    public function test_sincronizar_stock_despues_de_ordenes_refleja_el_stock_ya_neto(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $deposito = Deposito::first();
        app(StockService::class)->ajustar($producto, null, $deposito, 10, 'carga inicial');

        // Una Venta manual ya pendiente de empujar (stock 10 → 7).
        app(StockService::class)->registrarSalida($producto, null, $deposito, 3);

        Http::fake(function ($request) {
            if ($request->method() === 'PUT' && str_contains($request->url(), '/items/')) {
                return Http::response(['id' => 'MLA1'], 200);
            }

            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
            }

            // Una orden nueva de Mercado Libre por 2 unidades (stock 7 → 5).
            return Http::response(['results' => [[
                'id' => 9001, 'status' => 'paid',
                'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
                'total_amount' => 1210.0, 'currency_id' => 'ARS',
                'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'],
                'tags' => ['paid'],
                'order_items' => [[
                    'item' => ['id' => 'MLA1', 'title' => 'Producto', 'variation_id' => null],
                    'quantity' => 2, 'unit_price' => 605.0,
                ]],
            ]], 'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50]], 200);
        });

        $this->artisan('mercadolibre:sincronizar-ordenes --forzar')->assertExitCode(0);

        $this->assertDatabaseCount('ventas', 1);
        $this->assertSame(5.0, (float) \App\Models\Stock::where('producto_id', $producto->id)->where('deposito_id', $deposito->id)->value('cantidad'));

        $this->artisan('mercadolibre:sincronizar-stock --forzar')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/items/MLA1')
                && $request->data()['available_quantity'] === 5;
        });
    }
}
