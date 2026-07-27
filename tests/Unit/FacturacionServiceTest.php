<?php

namespace Tests\Unit;

use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Arca\ArcaIndisponibleException;
use App\Services\Arca\ClienteArca;
use App\Services\Arca\ClienteArcaFake;
use App\Services\Arca\Facturacion;
use App\Services\Arca\PrecondicionFacturacionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FacturacionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CondicionIva $ri;

    private CondicionIva $monotributo;

    private CondicionIva $consumidorFinal;

    protected function setUp(): void
    {
        parent::setUp();
        config(['negocio.facturacion_electronica_activo' => true]);

        $this->ri = CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);
        $this->monotributo = CondicionIva::create(['nombre' => 'Monotributista', 'codigo_afip' => '6', 'requiere_cuit' => true]);
        $this->consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);

        Empresa::create([
            'razon_social' => 'Emisor de Prueba', 'cuit' => '20111111112',
            'condicion_iva_id' => $this->ri->id, 'ambiente_arca' => 'testing',
        ]);

        CertificadoFiscal::create([
            'ambiente' => 'testing', 'certificado_path' => 'testing/cert.crt', 'clave_privada_path' => 'testing/key.enc',
            'fecha_emision' => now()->subYear(), 'fecha_vencimiento' => now()->addYear(), 'activo' => true,
        ]);
    }

    private function puntoVenta(): PuntoVenta
    {
        return PuntoVenta::create(['numero' => '0001', 'activo' => true]);
    }

    private function ventaConItems(CondicionIva $condicionCliente, ?string $cuit = null, ?PuntoVenta $puntoVenta = null): Venta
    {
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionCliente->id, 'cuit' => $cuit]);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'punto_venta_id' => $puntoVenta?->id,
        ]);
        VentaItem::factory()->create([
            'venta_id' => $venta->id,
            'cantidad' => 1, 'precio' => 1000, 'descuento_pct' => 0, 'iva_pct' => 21,
            'subtotal' => 1000, 'total' => 1210,
        ]);

        return $venta->fresh(['items', 'cliente']);
    }

    private function fake(): ClienteArcaFake
    {
        return app(ClienteArca::class);
    }

    public function test_aprobado_persiste_cae_numero_y_estado_con_importes_correctos(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);

        $comprobante = app(Facturacion::class)->facturar($venta);

        $this->assertSame('aprobado', $comprobante->estado);
        $this->assertNotNull($comprobante->cae);
        $this->assertNotNull($comprobante->cae_vencimiento);
        $this->assertSame(1, $comprobante->numero);
        $this->assertSame('B', $comprobante->tipo_comprobante);
        $this->assertEqualsWithDelta(1210.0, (float) $comprobante->importe_total, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $comprobante->importe_neto, 0.01);
        $this->assertEqualsWithDelta(210.0, (float) $comprobante->importe_iva, 0.01);
    }

    public function test_rechazo_de_arca_persiste_rechazado_con_respuesta_arca(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);
        $this->fake()->fijarModo(ClienteArcaFake::MODO_RECHAZADO);

        $comprobante = app(Facturacion::class)->facturar($venta);

        $this->assertSame('rechazado', $comprobante->estado);
        $this->assertNull($comprobante->cae);
        $this->assertNotEmpty($comprobante->respuesta_arca);
        // El detalle del rechazo queda legible para mostrar en la UI (§ US3).
        $this->assertSame(10015, $comprobante->respuesta_arca['Errors'][0]['Code']);
        $this->assertNotEmpty($comprobante->respuesta_arca['Errors'][0]['Msg']);
    }

    public function test_reintentar_pendientes_pasa_a_aprobado_cuando_arca_vuelve_a_estar_disponible(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);
        $this->fake()->fijarModo(ClienteArcaFake::MODO_CAIDA);
        $pendiente = app(Facturacion::class)->facturar($venta);
        $this->assertSame('pendiente', $pendiente->estado);

        $this->fake()->fijarModo(ClienteArcaFake::MODO_APROBADO);
        $resumen = app(Facturacion::class)->reintentarPendientes();

        $this->assertSame(['aprobados' => 1, 'rechazados' => 0, 'pendientes' => 0], $resumen);
        $this->assertSame('aprobado', $pendiente->fresh()->estado);
        $this->assertNotNull($pendiente->fresh()->cae);
        // Idempotente: sigue siendo el mismo registro (mismo id), no se creó uno nuevo.
        $this->assertSame(1, \App\Models\ComprobanteFiscal::count());
    }

    public function test_fallo_al_obtener_el_ticket_de_acceso_deja_pendiente_en_vez_de_romper(): void
    {
        // Regresión: un fallo de ARCA en obtenerTicketAcceso/ultimoAutorizado (no sólo en
        // solicitarCae) también debe resultar en "pendiente", nunca en una excepción sin capturar.
        $clienteArcaRoto = new class implements ClienteArca
        {
            public function obtenerTicketAcceso(string $ambiente): array
            {
                throw new ArcaIndisponibleException('WSAA no respondió (simulado).');
            }

            public function ultimoAutorizado(int $ptoVta, int $cbteTipo, string $ambiente): int
            {
                return 0;
            }

            public function solicitarCae(array $datosComprobante, string $ambiente): array
            {
                throw new ArcaIndisponibleException('No debería llegar acá.');
            }
        };
        $this->app->instance(ClienteArca::class, $clienteArcaRoto);

        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);

        $comprobante = app(Facturacion::class)->facturar($venta);

        $this->assertSame('pendiente', $comprobante->estado);
        $this->assertNull($comprobante->cae);
    }

    public function test_reintentar_pendientes_no_reintenta_los_ya_aprobados(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);
        app(Facturacion::class)->facturar($venta);

        $resumen = app(Facturacion::class)->reintentarPendientes();

        $this->assertSame(['aprobados' => 0, 'rechazados' => 0, 'pendientes' => 0], $resumen);
    }

    public function test_nunca_persiste_aprobado_sin_cae(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);
        $this->fake()->fijarModo(ClienteArcaFake::MODO_CAIDA);

        $comprobante = app(Facturacion::class)->facturar($venta);

        $this->assertSame('pendiente', $comprobante->estado);
        $this->assertNull($comprobante->cae);
    }

    public function test_no_factura_dos_veces_la_misma_venta(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);

        app(Facturacion::class)->facturar($venta);

        $this->expectException(PrecondicionFacturacionException::class);
        app(Facturacion::class)->facturar($venta->fresh());
    }

    public function test_numeracion_es_ultimo_autorizado_mas_uno(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta1 = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);
        $venta2 = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);

        $comprobante1 = app(Facturacion::class)->facturar($venta1);
        $comprobante2 = app(Facturacion::class)->facturar($venta2);

        $this->assertSame(1, $comprobante1->numero);
        $this->assertSame(2, $comprobante2->numero);
    }

    public function test_lock_impide_emision_concurrente_sobre_el_mismo_punto_y_tipo(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->consumidorFinal, null, $puntoVenta);

        // Simula que otro proceso ya tiene el lock de ese (ambiente, ptoVta, cbteTipo).
        $lock = Cache::lock('arca:emision:testing:0001:6', 15);
        $lock->get();

        try {
            $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);
            app(Facturacion::class)->facturar($venta);
        } finally {
            $lock->release();
        }
    }

    public function test_bloquea_sin_condicion_de_iva(): void
    {
        $cliente = Cliente::factory()->create(['condicion_iva_id' => null]);
        $puntoVenta = $this->puntoVenta();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'punto_venta_id' => $puntoVenta->id]);
        VentaItem::factory()->create(['venta_id' => $venta->id]);

        $this->expectException(PrecondicionFacturacionException::class);
        app(Facturacion::class)->facturar($venta->fresh(['items', 'cliente']));
    }

    public function test_bloquea_sin_cuit_cuando_la_condicion_lo_requiere(): void
    {
        $puntoVenta = $this->puntoVenta();
        $venta = $this->ventaConItems($this->monotributo, null, $puntoVenta);

        $this->expectException(PrecondicionFacturacionException::class);
        app(Facturacion::class)->facturar($venta);
    }

    public function test_bloquea_sin_punto_de_venta_activo(): void
    {
        $venta = $this->ventaConItems($this->consumidorFinal, null, null);

        $this->expectException(PrecondicionFacturacionException::class);
        app(Facturacion::class)->facturar($venta);
    }
}
