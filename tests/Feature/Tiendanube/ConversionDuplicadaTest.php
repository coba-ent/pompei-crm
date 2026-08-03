<?php

namespace Tests\Feature\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 038, US1: mismos tres casos que ConversionDuplicadaTest de Mercado
 * Libre, para el pedido de Tiendanube (`tn_order_id`).
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
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuentaTesoreria->id]);
    }

    private function crearOrden(array $overrides = []): TiendanubeOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::firstOrCreate(['variant_id' => 1], ['producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create(array_replace([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ], $overrides));

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto',
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
        $tnOrderId = $orden->tn_order_id;

        $orden->fresh()->forceDelete();

        $ordenResincronizada = $this->crearOrden([
            'tn_order_id' => $tnOrderId,
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
        $this->assertSame((string) $orden->tn_order_id, (string) $resultado['venta']->tn_order_id);
    }

    public function test_venta_soft_deleted_sigue_bloqueando_la_reconversion(): void
    {
        $orden = $this->crearOrden();
        $conversor = app(ConversorOrdenAVenta::class);

        $primero = $conversor->convertir($orden, null, automatica: true);
        $this->assertTrue($primero['ok'], $primero['mensaje'] ?? '');
        $tnOrderId = $orden->tn_order_id;

        $primero['venta']->delete();
        $orden->fresh()->forceDelete();

        $ordenResincronizada = $this->crearOrden([
            'tn_order_id' => $tnOrderId,
            'estado_conversion' => 'lista',
        ]);

        $segundo = $conversor->convertir($ordenResincronizada, null, automatica: true);

        $this->assertFalse($segundo['ok']);
        $this->assertDatabaseCount('ventas', 1);
    }
}
