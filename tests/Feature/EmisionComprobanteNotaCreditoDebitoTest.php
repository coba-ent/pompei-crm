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

/** US3 — NC/ND sobre Venta con CAE obtiene su propio CAE referenciando el comprobante original. */
class EmisionComprobanteNotaCreditoDebitoTest extends TestCase
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
                    private int $llamadas = 0;

                    public function __construct() {}

                    public function consultarUltimoAutorizado(array $ta, int $pv, string $tipo): object
                    {
                        $this->llamadas++;

                        return json_decode(json_encode(['FECompUltimoAutorizadoResult' => ['CbteNro' => 4 + $this->llamadas]]));
                    }

                    public function consultarComprobante(array $ta, int $pv, string $tipo, int $numero): object
                    {
                        return json_decode(json_encode(['FECompConsultarResult' => ['ResultGet' => null]]));
                    }

                    public function solicitarCae(array $ta, array $comprobante): object
                    {
                        $cae = $comprobante['FeCabReq']['CbteTipo'] === 6 ? '71234500000001' : '71234500000002';

                        return json_decode(json_encode([
                            'FECAESolicitarResult' => [
                                'FeDetResp' => [
                                    'FECAEDetResponse' => [
                                        'Resultado' => 'A',
                                        'CAE' => $cae,
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

    private function crearVenta(Cliente $cliente): Venta
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_nota_de_credito_sobre_venta_con_cae_obtiene_su_propio_cae(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        // Spec 040: cobrar ya no dispara la emisión — se envía manualmente antes de crear la NC/ND.
        $this->postJson(route('ventas.enviarArca', $venta))->assertOk()->assertJsonPath('ok', true);

        $venta->refresh();
        $comprobanteVenta = $venta->comprobanteFiscal;
        $this->assertNotNull($comprobanteVenta);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $nota = $venta->notasCreditoDebito()->firstOrFail();
        $comprobanteNota = $nota->comprobanteFiscal;

        $this->assertNotNull($comprobanteNota);
        $this->assertSame('aprobado', $comprobanteNota->estado);
        $this->assertSame($comprobanteVenta->id, $comprobanteNota->comprobante_ajustado_id);
        $this->assertNotSame($comprobanteVenta->cae, $comprobanteNota->cae);
    }
}
