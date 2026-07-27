<?php

namespace Tests\Feature;

use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Arca\ClienteArca;
use App\Services\Arca\ClienteArcaFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturacionTest extends TestCase
{
    use RefreshDatabase;

    private CondicionIva $ri;

    private CondicionIva $consumidorFinal;

    private PuntoVenta $puntoVenta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['negocio.facturacion_electronica_activo' => true]);

        $this->ri = CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);
        $this->consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);

        Empresa::create([
            'razon_social' => 'Emisor de Prueba', 'cuit' => '20111111112',
            'condicion_iva_id' => $this->ri->id, 'ambiente_arca' => 'testing',
        ]);

        CertificadoFiscal::create([
            'ambiente' => 'testing', 'certificado_path' => 'testing/cert.crt', 'clave_privada_path' => 'testing/key.enc',
            'fecha_emision' => now()->subYear(), 'fecha_vencimiento' => now()->addYear(), 'activo' => true,
        ]);

        $this->puntoVenta = PuntoVenta::create(['numero' => '0001', 'activo' => true]);
    }

    private function ventaFacturable(?CondicionIva $condicion = null): Venta
    {
        $cliente = Cliente::factory()->create(['condicion_iva_id' => ($condicion ?? $this->consumidorFinal)->id]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'punto_venta_id' => $this->puntoVenta->id]);
        VentaItem::factory()->create(['venta_id' => $venta->id, 'subtotal' => 1000, 'total' => 1210]);

        return $venta;
    }

    public function test_happy_path_devuelve_201_con_cae_y_actualiza_estado_arca(): void
    {
        $venta = $this->ventaFacturable();

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(201);
        $this->assertSame('aprobado', $response->json('data.estado'));
        $this->assertNotNull($response->json('data.cae'));
        $this->assertSame('aprobado', $venta->fresh()->estadoArca());
    }

    public function test_bloquea_sin_condicion_de_iva(): void
    {
        $cliente = Cliente::factory()->create(['condicion_iva_id' => null]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'punto_venta_id' => $this->puntoVenta->id]);
        VentaItem::factory()->create(['venta_id' => $venta->id]);

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
        $this->assertArrayHasKey('arca', $response->json('errors'));
    }

    public function test_bloquea_sin_punto_de_venta_activo(): void
    {
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $this->consumidorFinal->id]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'punto_venta_id' => null]);
        VentaItem::factory()->create(['venta_id' => $venta->id]);

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
    }

    public function test_bloquea_sin_certificado_vigente(): void
    {
        CertificadoFiscal::query()->update(['activo' => false]);
        $venta = $this->ventaFacturable();

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
    }

    public function test_bloquea_con_certificado_vencido_con_mensaje_especifico(): void
    {
        CertificadoFiscal::query()->update([
            'fecha_vencimiento' => now()->subDay(),
            'fecha_emision' => now()->subYears(2)->subDay(),
        ]);
        $venta = $this->ventaFacturable();

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
        $mensaje = $response->json('errors.arca.0');
        $this->assertStringContainsString('vencido', $mensaje);
        $this->assertStringContainsString('Renueve', $mensaje);
    }

    public function test_venta_ya_facturada_no_se_puede_facturar_dos_veces(): void
    {
        $venta = $this->ventaFacturable();
        $this->postJson(route('ventas.facturar', $venta))->assertStatus(201);

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
    }

    public function test_el_flag_desactivado_bloquea_la_ruta(): void
    {
        config(['negocio.facturacion_electronica_activo' => false]);
        $venta = $this->ventaFacturable();

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(403);
    }

    public function test_rechazo_de_arca_devuelve_422_con_detalle(): void
    {
        app(ClienteArca::class)->fijarModo(ClienteArcaFake::MODO_RECHAZADO);
        $venta = $this->ventaFacturable();

        $response = $this->postJson(route('ventas.facturar', $venta));

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.arca'));
    }

    public function test_caida_de_arca_deja_pendiente_y_el_reintento_en_lote_lo_aprueba(): void
    {
        app(ClienteArca::class)->fijarModo(ClienteArcaFake::MODO_CAIDA);
        $venta = $this->ventaFacturable();

        $facturar = $this->postJson(route('ventas.facturar', $venta));
        $facturar->assertStatus(200);
        $this->assertSame('pendiente', $facturar->json('data.estado'));
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);

        app(ClienteArca::class)->fijarModo(ClienteArcaFake::MODO_APROBADO);
        $reintentar = $this->postJson(route('comprobantes.reintentar'));

        $reintentar->assertOk();
        $this->assertSame(1, $reintentar->json('data.aprobados'));
        $this->assertSame('aprobado', $venta->fresh()->estadoArca());
    }

    public function test_el_pdf_solo_se_genera_para_comprobantes_aprobados(): void
    {
        $venta = $this->ventaFacturable();
        $facturar = $this->postJson(route('ventas.facturar', $venta));
        $comprobanteId = $facturar->json('data.comprobante_id');

        $pdf = $this->get(route('comprobantes.pdf', $comprobanteId));

        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_el_pdf_de_un_comprobante_pendiente_devuelve_404(): void
    {
        app(ClienteArca::class)->fijarModo(ClienteArcaFake::MODO_CAIDA);
        $venta = $this->ventaFacturable();
        $facturar = $this->postJson(route('ventas.facturar', $venta));
        $comprobanteId = $facturar->json('data.comprobante_id');

        $pdf = $this->get(route('comprobantes.pdf', $comprobanteId));

        $pdf->assertStatus(404);
    }

    public function test_el_pdf_de_un_comprobante_rechazado_devuelve_404(): void
    {
        app(ClienteArca::class)->fijarModo(ClienteArcaFake::MODO_RECHAZADO);
        $venta = $this->ventaFacturable();
        $facturar = $this->postJson(route('ventas.facturar', $venta));
        $comprobanteId = $facturar->json('data.comprobante_id');

        $pdf = $this->get(route('comprobantes.pdf', $comprobanteId));

        $pdf->assertStatus(404);
    }
}
