<?php

namespace Tests\Feature\MercadoLibre;

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
 * Spec 038, US1: ninguna conversión (manual o automática) de un pedido de
 * Mercado Libre puede generar una segunda Venta para el mismo pedido de
 * origen, aunque la orden se haya borrado y resincronizado.
 */
class ConversionDuplicadaTest extends TestCase
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
            'estado' => 'conectada', 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    private function crearOrden(array $overrides = []): MercadoLibreOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => 'MLA1'], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ], $overrides));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    public function test_reconversion_tras_borrado_y_resincronizacion_se_rechaza_sin_duplicar(): void
    {
        $orden = $this->crearOrden();
        $conversor = app(ConversorOrdenAVenta::class);

        $primero = $conversor->convertir($orden, null, automatica: true);
        $this->assertTrue($primero['ok'], $primero['mensaje'] ?? '');
        $mlOrderId = $orden->ml_order_id;

        // Simula el borrado accidental de la fila (orden desvinculada primero, como hace
        // eliminarSiSinVenta) y su resincronización posterior con el mismo ml_order_id.
        $orden->fresh()->forceDelete();

        $ordenResincronizada = $this->crearOrden([
            'ml_order_id' => $mlOrderId,
            'estado_conversion' => 'lista',
        ]);

        $segundo = $conversor->convertir($ordenResincronizada, null, automatica: true);

        $this->assertFalse($segundo['ok']);
        $this->assertSame('Esta orden ya tiene una Venta asociada.', $segundo['mensaje']);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('cobros', 1);
        $this->assertDatabaseCount('stocks', 1);
    }

    public function test_conversion_de_pedido_nunca_convertido_se_completa_con_normalidad(): void
    {
        $orden = $this->crearOrden();

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame($orden->ml_order_id, $resultado['venta']->ml_order_id);
    }

    public function test_venta_soft_deleted_sigue_bloqueando_la_reconversion(): void
    {
        $orden = $this->crearOrden();
        $conversor = app(ConversorOrdenAVenta::class);

        $primero = $conversor->convertir($orden, null, automatica: true);
        $this->assertTrue($primero['ok'], $primero['mensaje'] ?? '');
        $mlOrderId = $orden->ml_order_id;

        $primero['venta']->delete();
        $orden->fresh()->forceDelete();

        $ordenResincronizada = $this->crearOrden([
            'ml_order_id' => $mlOrderId,
            'estado_conversion' => 'lista',
        ]);

        $segundo = $conversor->convertir($ordenResincronizada, null, automatica: true);

        $this->assertFalse($segundo['ok']);
        $this->assertDatabaseCount('ventas', 1);
    }
}
