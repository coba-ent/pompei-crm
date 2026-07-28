<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FR-032a/SC-004a — la prueba más importante de la spec: 10 intentos de
 * conversión sobre la misma orden (manual y automática mezclados) deben
 * producir exactamente una Venta, una cobranza y un movimiento de stock.
 */
class MercadoLibreConcurrenciaTest extends TestCase
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

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    public function test_diez_intentos_concurrentes_producen_exactamente_una_venta_una_cobranza_y_un_movimiento_de_stock(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '5000001', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => 1210.00, 'moneda' => 'ARS', 'comprador_ml_id' => '1', 'comprador_apodo' => 'COMPRADOR',
            'comprador_condicion_iva' => 'Consumidor Final', 'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        $conversor = app(ConversorOrdenAVenta::class);
        $resultados = [];

        for ($i = 0; $i < 10; $i++) {
            $automatica = $i % 2 === 0;
            $resultados[] = $conversor->convertir($orden->fresh(), auth()->id(), automatica: $automatica);
        }

        $exitosos = collect($resultados)->where('ok', true);

        $this->assertCount(1, $exitosos, 'Debe haber exactamente un intento exitoso entre los 10.');
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('cobros', 1);
        $this->assertDatabaseCount('movimientos_stock', 1);
        $this->assertSame(1, MercadoLibreOrden::where('id', $orden->id)->where('estado_conversion', 'convertida')->count());
    }
}
