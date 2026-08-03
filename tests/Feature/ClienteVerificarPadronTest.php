<?php

namespace Tests\Feature;

use App\Models\CertificadoFiscal;
use App\Models\Rol;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Database\Seeders\CondicionIvaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T007 (spec 037): `GET /clientes/verificar-documento` extendido con la consulta al padrón. */
class ClienteVerificarPadronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new CondicionIvaSeeder())->run();

        CertificadoFiscal::create([
            'cuit' => '20111111112',
            'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt',
            'ruta_clave_privada' => 'arca/test.key',
            'activo' => true,
        ]);
    }

    private function respuestaPadronOk(): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'datosGenerales' => [
                    'razonSocial' => 'ACME SA',
                    'domicilioFiscal' => ['direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    'estadoClave' => 'ACTIVO',
                ],
                'datosRegimenGeneral' => [
                    'impuesto' => [['descripcionImpuesto' => 'IVA RESPONSABLE INSCRIPTO']],
                ],
            ],
        ]));
    }

    private function bindWsaa(): void
    {
        $this->app->bind(ClienteWsaa::class, fn () => new class extends ClienteWsaa
        {
            public function __construct() {}

            public function obtenerTicketAcceso(string $servicio = 'wsfe'): array
            {
                return ['token' => 'tok', 'sign' => 'sign'];
            }
        });
    }

    public function test_padron_encuentra_el_contribuyente(): void
    {
        $this->bindWsaa();
        $respuesta = $this->respuestaPadronOk();
        $this->mockearClientePadron(fn () => $respuesta);

        $resp = $this->getJson('/clientes/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('padron.consultado', true);
        $resp->assertJsonPath('padron.encontrado', true);
        $resp->assertJsonPath('padron.razon_social', 'ACME SA');
        $resp->assertJsonPath('padron.condicion_iva', 'Responsable Inscripto');
    }

    public function test_padron_no_encuentra_el_cuit(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => json_decode(json_encode(['personaReturn' => null])));

        $resp = $this->getJson('/clientes/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('padron.consultado', true);
        $resp->assertJsonPath('padron.encontrado', false);
    }

    public function test_arca_no_disponible_no_bloquea_la_respuesta(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $resp = $this->getJson('/clientes/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('valido', true);
        $resp->assertJsonPath('padron.consultado', false);
    }

    public function test_tipo_de_documento_no_cuit_no_agrega_clave_padron(): void
    {
        $resp = $this->getJson('/clientes/verificar-documento?tipo_documento=DNI&numero=30123456');

        $resp->assertOk();
        $resp->assertJson(['aplica' => false]);
        $resp->assertJsonMissing(['padron' => []]);
    }

    private function mockearClientePadron(\Closure $respuestaOControlador): void
    {
        $this->app->bind(ClientePadron::class, fn () => new class($respuestaOControlador) extends ClientePadron
        {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function consultarConstancia(array $ticketAcceso, string $cuit): object
            {
                return ($this->callback)();
            }
        });
    }
}
