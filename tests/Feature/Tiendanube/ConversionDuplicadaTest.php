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

    public function test_orden_pagada_despues_de_las_21_argentinas_queda_con_la_fecha_local(): void
    {
        // 00:35 UTC del 30/07 = 21:35 del 29/07 en Argentina (caso real: orden #108).
        $orden = $this->crearOrden([
            'fecha_creada' => '2026-07-30 00:35:45', 'fecha_cerrada' => '2026-07-30 00:35:45',
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('2026-07-29', $resultado['venta']->fresh()->fecha_emision->toDateString());
    }

    public function test_orden_con_envio_y_descuento_deja_la_linea_coherente_con_el_total(): void
    {
        // Caso real (orden #110): producto 187.549,20 − descuento por transferencia 18.754,92
        // + envío 50.755,80 = 219.550,08. La diferencia con el producto va con IVA incluido.
        $orden = $this->crearOrden(['total' => 219550.08]);
        $orden->items()->update(['precio_unitario' => 187549.20, 'total_linea' => 187549.20]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $venta = $resultado['venta']->fresh('items');
        $this->assertEqualsWithDelta(219550.08, (float) $venta->total, 0.001);
        $this->assertEqualsWithDelta(219550.08, (float) $venta->items->first()->subtotal_con_iva, 0.02);
        $this->assertEqualsWithDelta(181446.35, (float) $venta->items->first()->precio_unitario, 0.02);
    }

    public function test_cliente_nuevo_se_crea_con_documento_telefono_y_domicilio_de_la_orden(): void
    {
        $orden = $this->crearOrden([
            'billing_document_number' => '17119795',
            'payload' => [
                'contact_phone' => '+5491100000000', 'billing_address' => 'Av. Cabildo', 'billing_number' => '1234',
                'billing_floor' => '3B', 'billing_city' => 'CABA', 'billing_province' => 'Capital Federal', 'billing_zipcode' => '1426',
            ],
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $cliente = $resultado['venta']->cliente;
        $this->assertSame('17119795', $cliente->cuit);
        $this->assertSame('DNI', $cliente->tipo_documento);
        $this->assertSame('+5491100000000', $cliente->telefono);
        $this->assertSame('Av. Cabildo 1234 3B', $cliente->domicilio);
        $this->assertSame('CABA', $cliente->localidad);
        $this->assertSame('1426', $cliente->cp);
    }

    public function test_campos_no_informado_no_se_copian_al_cliente(): void
    {
        $orden = $this->crearOrden([
            'payload' => ['billing_address' => 'No informado', 'billing_number' => 'No informado', 'billing_city' => 'No informado', 'billing_zipcode' => 'No informado'],
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $cliente = $resultado['venta']->cliente;
        $this->assertNull($cliente->domicilio);
        $this->assertNull($cliente->localidad);
        $this->assertNull($cliente->cp);
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
