<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 038, US2: no se puede eliminar una orden de Mercado Libre/Tiendanube
 * con `venta_id` cargado por ningún camino de borrado existente en la app.
 */
class BorradoOrdenConVentaTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_puede_eliminar_una_orden_ml_con_venta_asociada(): void
    {
        $venta = Venta::factory()->create(['origen' => 'mercadolibre']);
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => 'ML-1', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '1', 'venta_id' => $venta->id, 'sincronizada_en' => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $orden->eliminarSiSinVenta();
        } finally {
            $this->assertDatabaseHas('ml_ordenes', ['id' => $orden->id]);
        }
    }

    public function test_se_puede_eliminar_una_orden_ml_sin_venta_asociada(): void
    {
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => 'ML-2', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '2', 'sincronizada_en' => now(),
        ]);

        $this->assertTrue($orden->eliminarSiSinVenta());
        $this->assertDatabaseMissing('ml_ordenes', ['id' => $orden->id]);
    }

    public function test_no_se_puede_eliminar_una_orden_tn_con_venta_asociada(): void
    {
        $venta = Venta::factory()->create(['origen' => 'tiendanube']);
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 'TN-1', 'status' => 'closed', 'payment_status' => 'paid',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'venta_id' => $venta->id, 'sincronizada_en' => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $orden->eliminarSiSinVenta();
        } finally {
            $this->assertDatabaseHas('tn_ordenes', ['id' => $orden->id]);
        }
    }

    public function test_se_puede_eliminar_una_orden_tn_sin_venta_asociada(): void
    {
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 'TN-2', 'status' => 'closed', 'payment_status' => 'paid',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'sincronizada_en' => now(),
        ]);

        $this->assertTrue($orden->eliminarSiSinVenta());
        $this->assertDatabaseMissing('tn_ordenes', ['id' => $orden->id]);
    }
}
