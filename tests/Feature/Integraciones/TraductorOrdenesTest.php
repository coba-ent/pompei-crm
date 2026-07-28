<?php

namespace Tests\Feature\Integraciones;

use App\Services\MercadoLibre\TraductorOrdenes;
use Tests\TestCase;

/**
 * T026 — TraductorOrdenes contra las formas de respuesta verificadas en
 * research.md §R2/§R8: orden simple, con variantes, con descuentos, sin datos
 * fiscales, con alerta de fraude y respuesta parcial 206.
 */
class TraductorOrdenesTest extends TestCase
{
    protected bool $autenticado = false;

    private function traductor(): TraductorOrdenes
    {
        return new TraductorOrdenes();
    }

    private function ordenBase(array $overrides = []): array
    {
        return array_replace([
            'id' => 2000012345678,
            'status' => 'paid',
            'date_created' => '2026-07-20T10:00:00.000-04:00',
            'date_closed' => '2026-07-20T10:05:00.000-04:00',
            'total_amount' => 1210.0,
            'currency_id' => 'ARS',
            'buyer' => [
                'id' => 555444333,
                'nickname' => 'COMPRADOR123',
                'billing_info' => ['id' => 987, 'name' => 'Juan', 'last_name' => 'Pérez'],
            ],
            'tags' => ['paid'],
            'order_items' => [[
                'item' => [
                    'id' => 'MLA1927008393',
                    'title' => 'Producto de prueba',
                    'variation_id' => null,
                    'seller_sku' => 'SKU-1',
                ],
                'quantity' => 1,
                'unit_price' => 1210.0,
                'gross_price' => 1210.0,
                'sale_fee' => 121.0,
            ]],
        ], $overrides);
    }

    public function test_traduce_una_orden_simple(): void
    {
        $orden = $this->traductor()->traducirOrden($this->ordenBase());

        $this->assertSame('2000012345678', $orden['ml_order_id']);
        $this->assertSame('pagada', $orden['estado_orden']);
        $this->assertSame(1210.0, $orden['total']);
        $this->assertSame('555444333', $orden['comprador_ml_id']);
        $this->assertSame('COMPRADOR123', $orden['comprador_apodo']);
        $this->assertFalse($orden['tiene_alerta_fraude']);
        $this->assertFalse($orden['es_prueba']);
    }

    public function test_traduce_los_items_de_la_orden(): void
    {
        $items = $this->traductor()->traducirItems($this->ordenBase());

        $this->assertCount(1, $items);
        $this->assertSame('MLA1927008393', $items[0]['ml_item_id']);
        $this->assertNull($items[0]['ml_variation_id']);
        $this->assertSame(1.0, $items[0]['cantidad']);
        $this->assertSame(1210.0, $items[0]['precio_unitario']);
        $this->assertSame(1210.0, $items[0]['total_linea']);
        $this->assertSame(121.0, $items[0]['comision_ml']);
    }

    public function test_orden_con_variantes_se_detecta_como_no_soportada(): void
    {
        $orden = $this->ordenBase();
        $orden['order_items'][0]['item']['variation_id'] = 123456;

        $traductor = $this->traductor();
        $items = $traductor->traducirItems($orden);

        $this->assertSame('123456', $items[0]['ml_variation_id']);
        $this->assertTrue($traductor->tieneItemConVariante($items));
    }

    public function test_orden_con_descuento_respeta_el_unit_price_ya_neto(): void
    {
        $orden = $this->ordenBase();
        $orden['order_items'][0]['unit_price'] = 900.0;
        $orden['order_items'][0]['gross_price'] = 1210.0;
        $orden['total_amount'] = 900.0;

        $items = $this->traductor()->traducirItems($orden);

        $this->assertSame(900.0, $items[0]['precio_unitario']);
        $this->assertSame(1210.0, $items[0]['precio_bruto']);
        $this->assertSame(900.0, $items[0]['total_linea']);
    }

    public function test_orden_sin_datos_fiscales_no_rompe_la_traduccion(): void
    {
        $orden = $this->ordenBase();
        unset($orden['buyer']['billing_info']);

        $traducida = $this->traductor()->traducirOrden($orden);

        $this->assertNull($traducida['billing_info_id']);
    }

    public function test_datos_fiscales_se_traducen_desde_billing_info(): void
    {
        $billingInfo = [
            'identification' => ['type' => 'CUIT', 'number' => '20304050607'],
            'taxes' => ['taxpayer_type' => ['description' => 'Monotributo']],
        ];

        $datos = $this->traductor()->traducirDatosFiscales($billingInfo);

        $this->assertSame('CUIT', $datos['comprador_doc_tipo']);
        $this->assertSame('20304050607', $datos['comprador_doc_numero']);
        $this->assertSame('Monotributo', $datos['comprador_condicion_iva']);
    }

    public function test_orden_con_alerta_de_fraude_se_marca(): void
    {
        $orden = $this->ordenBase(['tags' => ['paid', 'fraud_risk_detected']]);

        $traducida = $this->traductor()->traducirOrden($orden);

        $this->assertTrue($traducida['tiene_alerta_fraude']);
        $this->assertTrue($this->traductor()->tieneAlertaFraude($traducida));
    }

    public function test_respuesta_parcial_registra_los_datos_faltantes(): void
    {
        $traducida = $this->traductor()->traducirOrden($this->ordenBase(), 'buyer');

        $this->assertSame('buyer', $traducida['datos_faltantes']);
    }

    public function test_moneda_distinta_a_la_del_negocio_se_detecta(): void
    {
        $orden = $this->ordenBase(['currency_id' => 'USD']);

        $traducida = $this->traductor()->traducirOrden($orden);

        $this->assertFalse($this->traductor()->monedaValida($traducida));
    }
}
