<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CertificadoFiscal;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\ClienteWsfev1;
use App\Services\Arca\EmisorComprobante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 040 — envío manual a ARCA (ex US1 de spec 034, ahora disparado por acción explícita, no por el cobro). */
class EmisionComprobanteVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        FuncionAvanzada::create(['clave' => 'facturacion_electronica', 'nombre' => 'Facturacion electronica', 'descripcion' => 'Emitir CAE.', 'orden' => 1, 'disponible' => true, 'activa' => true]);
        PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);
        CertificadoFiscal::create([
            'cuit' => '20111111112',
            'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt',
            'ruta_clave_privada' => 'arca/test.key',
            'activo' => true,
        ]);

        $this->app->bind(EmisorComprobante::class, function () {
            return new EmisorComprobante(
                wsaaFactory: fn () => new class extends ClienteWsaa
                {
                    public function __construct() {}

                    public function obtenerTicketAcceso(string $servicio = 'wsfe'): array
                    {
                        return ['token' => 'tok', 'sign' => 'sign'];
                    }
                },
                wsfeFactory: fn () => new class extends ClienteWsfev1
                {
                    public function __construct() {}

                    public function consultarUltimoAutorizado(array $ta, int $pv, string $tipo): object
                    {
                        return json_decode(json_encode(['FECompUltimoAutorizadoResult' => ['CbteNro' => 5]]));
                    }

                    public function consultarComprobante(array $ta, int $pv, string $tipo, int $numero): object
                    {
                        return json_decode(json_encode(['FECompConsultarResult' => ['ResultGet' => null]]));
                    }

                    public function solicitarCae(array $ta, array $comprobante): object
                    {
                        return json_decode(json_encode([
                            'FECAESolicitarResult' => [
                                'FeDetResp' => [
                                    'FECAEDetResponse' => [
                                        'Resultado' => 'A',
                                        'CAE' => '71234567890123',
                                        'CAEFchVto' => '20261231',
                                    ],
                                ],
                            ],
                        ]));
                    }
                },
            );
        });
    }

    private function crearVenta(Cliente $cliente, ?array $items = null, ?float $descuentoGeneralPct = null): Venta
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => $items ?? [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
            'descuento_general_pct' => $descuentoGeneralPct,
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    public function test_enviar_a_arca_manualmente_obtiene_cae_y_persiste_comprobante_fiscal_aprobado(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        // Cobrar ya NO dispara la emisión (spec 040) — se verifica explícitamente.
        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();
        $this->assertNull($venta->fresh()->comprobanteFiscal);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk()->assertJsonPath('ok', true);

        $comprobante = $venta->fresh()->comprobanteFiscal;
        $this->assertNotNull($comprobante);
        $this->assertSame('aprobado', $comprobante->estado);
        $this->assertSame('71234567890123', $comprobante->cae);
        $this->assertNotNull($comprobante->cae_vencimiento);
        $this->assertSame('0001-00000006', $comprobante->numero);
    }

    public function test_enviar_a_arca_una_venta_con_alicuotas_mixtas_obtiene_cae(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $venta = $this->crearVenta($cliente, [
            ['descripcion' => 'Producto 21%', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ['descripcion' => 'Producto 10.5%', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '10.5'],
        ]);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('aprobado', $venta->fresh()->comprobanteFiscal->estado);
    }

    /** Spec 044 — el descuento general prorrateado a neto e IVA destraba la emisión que spec 042 rechazaba. */
    public function test_enviar_a_arca_una_venta_con_descuento_general_obtiene_cae(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $venta = $this->crearVenta($cliente, [
            ['descripcion' => 'Producto 1', 'cantidad' => 1, 'precio_unitario' => 157879.22, 'iva_pct' => '21'],
            ['descripcion' => 'Producto 2', 'cantidad' => 1, 'precio_unitario' => 49859.48, 'iva_pct' => '21'],
            ['descripcion' => 'Producto 3', 'cantidad' => 1, 'precio_unitario' => 91308.22, 'iva_pct' => '21'],
        ], descuentoGeneralPct: 15);

        $this->assertEqualsWithDelta(307569.76, $venta->total, 0.01);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('aprobado', $venta->fresh()->comprobanteFiscal->estado);
    }

    public function test_enviar_a_arca_venta_de_cliente_sin_condicion_iva_es_rechazada_sin_contactar_arca(): void
    {
        $cliente = Cliente::factory()->conCuit('20111111112')->create(['condicion_iva_id' => null]);
        $venta = $this->crearVenta($cliente);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk()->assertJsonPath('ok', false);
        $this->assertStringContainsString('Condición de IVA', $response->json('mensaje'));
        $this->assertNull($venta->fresh()->comprobanteFiscal);
    }
}
