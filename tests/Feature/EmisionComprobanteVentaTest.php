<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CertificadoFiscal;
use App\Models\CuentaTesoreria;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\ClienteWsfev1;
use App\Services\Arca\EmisorComprobante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 — cobro de Venta obtiene CAE real y persiste ComprobanteFiscal aprobado. */
class EmisionComprobanteVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

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

    private function crearVenta(Cliente $cliente): Venta
    {
        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_cobro_de_venta_obtiene_cae_y_persiste_comprobante_fiscal_aprobado(): void
    {
        $consumidorFinal = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $response = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $comprobante = $venta->fresh()->comprobanteFiscal;
        $this->assertNotNull($comprobante);
        $this->assertSame('aprobado', $comprobante->estado);
        $this->assertSame('71234567890123', $comprobante->cae);
        $this->assertNotNull($comprobante->cae_vencimiento);
        $this->assertSame('0001-00000006', $comprobante->numero);
    }
}
