<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConversion;
use App\Services\Tiendanube\TraductorOrdenes;
use Tests\TestCase;

/**
 * T018 (spec 017): `TraductorOrdenes` mapea `status`+`payment_status` al
 * estado de conversión base (FR-007a) y descarta por completo las órdenes
 * `storefront=meli` (FR-012a, SC-010) antes de que lleguen a persistirse.
 */
class TiendanubeTraductorOrdenesTest extends TestCase
{
    private function ordenCruda(array $overrides = []): array
    {
        return array_replace([
            'id' => 555,
            'status' => 'open',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unpacked',
            'completed_at' => now()->toIso8601String(),
            'total' => ['amount' => 1210.0, 'currency' => 'ARS'],
            'storefront' => 'store',
            'customer' => ['id' => 42, 'email' => 'comprador@test.com', 'name' => 'Comprador', 'cpf_cnpj' => null],
            'items' => [],
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
            'items' => [[
                'product_id' => 10, 'variant_id' => 20, 'name' => 'Remera',
                'variant_values' => ['Rojo', 'M'], 'sku' => 'REM-R-M',
                'quantity' => 2, 'price' => ['amount' => 100, 'currency' => 'ARS'],
            ]],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('Rojo / M', $items[0]['nombre_variante']);
        $this->assertSame(200.0, $items[0]['total_linea']);
    }

    public function test_traducir_items_variante_virtual_sin_variant_values_da_nombre_nulo(): void
    {
        $items = (new TraductorOrdenes())->traducirItems([
            'items' => [[
                'product_id' => 10, 'variant_id' => 20, 'name' => 'Producto sin variantes',
                'variant_values' => [], 'quantity' => 1, 'price' => ['amount' => 50, 'currency' => 'ARS'],
            ]],
        ]);

        $this->assertNull($items[0]['nombre_variante']);
    }
}
