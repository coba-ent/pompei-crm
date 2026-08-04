<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CertificadoFiscal;
use App\Models\CuentaTesoreria;
use App\Models\FuncionAvanzada;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\ClienteWsfev1;
use App\Services\Arca\EmisorComprobante;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US4 — rechazo de ARCA y caída del servicio no dejan la Venta en estado ambiguo ni duplican comprobantes. */
class EmisionComprobanteRechazoTest extends TestCase
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
    }

    private function crearVenta(Cliente $cliente, string $tipoComprobante = 'A'): Venta
    {
        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => $tipoComprobante,
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_rechazo_por_cuit_invalido_no_crea_comprobante_aprobado_y_deja_venta_a_cobrar(): void
    {
        $riSinCuit = CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $riSinCuit->id, 'cuit' => null]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente, 'A');

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk()->assertJsonPath('ok', false);
        $this->assertNotNull($response->json('mensaje'));

        $venta->refresh();
        $this->assertNull($venta->comprobanteFiscal);
        $this->assertSame('cobrada', $venta->estadoCobro());
    }

    public function test_timeout_seguido_de_reintento_reconcilia_sin_duplicar_comprobante(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente, 'B');

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
                        // ARCA ya asignó CAE en un intento anterior cuya respuesta se perdió (timeout).
                        return json_decode(json_encode([
                            'FECompConsultarResult' => [
                                'ResultGet' => [
                                    'CAE' => '71234567890999',
                                    'CAEFchVto' => '20261231',
                                ],
                            ],
                        ]));
                    }

                    public function solicitarCae(array $ta, array $comprobante): object
                    {
                        throw new ArcaNoDisponibleException('No debería llamarse: ya está reconciliado.');
                    }
                },
            );
        });

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertOk();

        $venta->refresh();
        $this->assertNotNull($venta->comprobanteFiscal);
        $this->assertSame('aprobado', $venta->comprobanteFiscal->estado);
        $this->assertSame('71234567890999', $venta->comprobanteFiscal->cae);
        $this->assertSame(1, \App\Models\ComprobanteFiscal::count());
    }
}
