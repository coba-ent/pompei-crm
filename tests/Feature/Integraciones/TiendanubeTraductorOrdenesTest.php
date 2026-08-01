<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConversion;
use App\Services\Tiendanube\TraductorOrdenes;
use Tests\TestCase;

/**
 * T018 (spec 017, corregido post-024 contra la REST API real): `TraductorOrdenes`
 * mapea `status`+`payment_status` al estado de conversión base (FR-007a) y
 * descarta por completo las órdenes `storefront=meli` (FR-012a, SC-010) antes
 * de que lleguen a persistirse. Formato de campos verificado empíricamente
 * contra la cuenta real (spec 024): sin objeto `customer`, `total`/`price`
 * planos (no `{amount, currency}`), `completed_at` como objeto
 * `{"date": ..., "timezone_type": ..., "timezone": ...}`.
 */
class TiendanubeTraductorOrdenesTest extends TestCase
{
    private function ordenCruda(array $overrides = []): array
    {
        return array_replace([
            'id' => 555,
            'status' => 'open',
            'payment_status' => 'paid',
            'shipping_status' => 'unpacked',
            'completed_at' => ['date' => now()->format('Y-m-d H:i:s.u'), 'timezone_type' => 3, 'timezone' => 'UTC'],
            'total' => '1210.00',
            'currency' => 'ARS',
            'storefront' => 'store',
            'contact_email' => 'comprador@test.com',
            'contact_name' => 'Comprador',
            'contact_identification' => '',
            'products' => [],
        ], $overrides);
    }

    public function test_orden_storefront_meli_se_descarta_por_completo(): void
    {
        $resultado = (new TraductorOrdenes())->traducirOrden($this->ordenCruda(['storefront' => 'meli']));

        $this->assertNull($resultado);
    }

    public function test_storefront_ausente_o_distinto_de_meli_no_se_descarta(): void
    {
        $this->assertNotNull((new TraductorOrdenes())->traducirOrden($this->ordenCruda(['storefront' => null])));
        $this->assertNotNull((new TraductorOrdenes())->traducirOrden($this->ordenCruda(['storefront' => 'mobile'])));
    }

    public function test_completed_at_objeto_se_extrae_correctamente(): void
    {
        $resultado = (new TraductorOrdenes())->traducirOrden($this->ordenCruda([
            'completed_at' => ['date' => '2026-07-30 12:10:18.000000', 'timezone_type' => 3, 'timezone' => 'UTC'],
        ]));

        $this->assertSame('2026-07-30 12:10:18.000000', $resultado['fecha_creada']);
        $this->assertSame('2026-07-30 12:10:18.000000', $resultado['fecha_cerrada']);
    }

    public function test_completed_at_ausente_usa_created_at_como_respaldo(): void
    {
        $resultado = (new TraductorOrdenes())->traducirOrden($this->ordenCruda([
            'completed_at' => null,
            'created_at' => '2026-07-30T12:10:18+0000',
        ]));

        $this->assertSame('2026-07-30T12:10:18+0000', $resultado['fecha_creada']);
    }

    public function test_total_y_moneda_se_leen_como_campos_planos(): void
    {
        $resultado = (new TraductorOrdenes())->traducirOrden($this->ordenCruda(['total' => '262252.00', 'currency' => 'ARS']));

        $this->assertSame(262252.0, $resultado['total']);
        $this->assertSame('ARS', $resultado['moneda']);
    }

    public function test_comprador_se_resuelve_de_los_campos_contact_planos_sin_id_estable(): void
    {
        $resultado = (new TraductorOrdenes())->traducirOrden($this->ordenCruda([
            'contact_email' => 'ventas@test.com', 'contact_name' => 'Cliente Test', 'contact_identification' => '20304050607',
        ]));

        $this->assertNull($resultado['tn_customer_id']);
        $this->assertSame('ventas@test.com', $resultado['comprador_email']);
        $this->assertSame('Cliente Test', $resultado['comprador_nombre']);
        $this->assertSame('20304050607', $resultado['billing_document_number']);
    }

    /** @dataProvider estadosProvider */
    public function test_mapeo_de_estados_fr007a(string $status, string $paymentStatus, EstadoConversion $esperado): void
    {
        $resultado = (new TraductorOrdenes())->estadoBaseDesdeCrudo($status, $paymentStatus);

        $this->assertSame($esperado, $resultado);
    }

    public static function estadosProvider(): array
    {
        return [
            'open + pending -> pendiente' => ['open', 'pending', EstadoConversion::PendientePago],
            'open + authorized -> pendiente' => ['open', 'authorized', EstadoConversion::PendientePago],
            'open + partially_paid -> pendiente' => ['open', 'partially_paid', EstadoConversion::PendientePago],
            'open + abandoned -> pendiente' => ['open', 'abandoned', EstadoConversion::PendientePago],
            'open + paid -> lista (candidata)' => ['open', 'paid', EstadoConversion::Lista],
            'closed + paid -> lista (candidata)' => ['closed', 'paid', EstadoConversion::Lista],
            'cancelled -> cancelada' => ['cancelled', 'pending', EstadoConversion::Cancelada],
            'open + refunded -> cancelada' => ['open', 'refunded', EstadoConversion::Cancelada],
            'open + partially_refunded -> cancelada' => ['open', 'partially_refunded', EstadoConversion::Cancelada],
            'open + voided -> cancelada' => ['open', 'voided', EstadoConversion::Cancelada],
        ];
    }

    public function test_moneda_valida_solo_ars(): void
    {
        $traductor = new TraductorOrdenes();

        $this->assertTrue($traductor->monedaValida('ARS'));
        $this->assertFalse($traductor->monedaValida('USD'));
    }

    public function test_traducir_items_arma_nombre_de_variante_concatenando_variant_values(): void
    {
        $items = (new TraductorOrdenes())->traducirItems([
            'products' => [[
                'product_id' => 10, 'variant_id' => 20, 'name' => 'Remera',
                'variant_values' => ['Rojo', 'M'], 'sku' => 'REM-R-M',
                'quantity' => 2, 'price' => '100.00',
            ]],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('Rojo / M', $items[0]['nombre_variante']);
        $this->assertSame(200.0, $items[0]['total_linea']);
    }

    public function test_traducir_items_variante_virtual_sin_variant_values_da_nombre_nulo(): void
    {
        $items = (new TraductorOrdenes())->traducirItems([
            'products' => [[
                'product_id' => 10, 'variant_id' => 20, 'name' => 'Producto sin variantes',
                'variant_values' => [], 'quantity' => 1, 'price' => '50.00',
            ]],
        ]);

        $this->assertNull($items[0]['nombre_variante']);
    }
}
