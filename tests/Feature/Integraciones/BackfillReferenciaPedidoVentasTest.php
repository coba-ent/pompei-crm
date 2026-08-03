<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 038, FR-010/R4: el comando de backfill completa ml_order_id/tn_order_id
 * en Ventas históricas a partir de la orden vigente que las referencia.
 */
class BackfillReferenciaPedidoVentasTest extends TestCase
{
    use RefreshDatabase;

    public function test_completa_ml_order_id_y_tn_order_id_de_ventas_con_orden_vigente(): void
    {
        $ventaMl = Venta::factory()->create(['origen' => 'mercadolibre', 'ml_order_id' => null]);
        MercadoLibreOrden::create([
            'ml_order_id' => 'ML-777', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '777', 'venta_id' => $ventaMl->id, 'sincronizada_en' => now(),
        ]);

        $ventaTn = Venta::factory()->create(['origen' => 'tiendanube', 'tn_order_id' => null]);
        TiendanubeOrden::create([
            'tn_order_id' => 'TN-888', 'status' => 'closed', 'payment_status' => 'paid',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'venta_id' => $ventaTn->id, 'sincronizada_en' => now(),
        ]);

        $this->artisan('ventas:backfill-referencia-pedido')->assertSuccessful();

        $this->assertSame('ML-777', $ventaMl->fresh()->ml_order_id);
        $this->assertSame('TN-888', $ventaTn->fresh()->tn_order_id);
    }

    public function test_no_toca_ventas_cuya_orden_de_origen_ya_no_existe(): void
    {
        $venta = Venta::factory()->create(['origen' => 'mercadolibre', 'ml_order_id' => null]);

        $this->artisan('ventas:backfill-referencia-pedido')->assertSuccessful();

        $this->assertNull($venta->fresh()->ml_order_id);
    }
}
