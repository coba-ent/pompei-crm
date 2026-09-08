<?php

namespace Tests\Feature;

use App\Models\CertificadoFiscal;
use App\Models\Rol;
use App\Services\Arca\ClienteConstanciaInscripcion;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Database\Seeders\CondicionIvaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Espejo de ClienteVerificarPadronTest.php (spec 100): `GET /proveedores/verificar-documento`
 * pasa a consultar el padrón de ARCA además de validar el dígito verificador.
 */
class ProveedorVerificarPadronTest extends TestCase
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
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    ],
                    'estadoClave' => 'ACTIVO',
                    'datosRegimenGeneral' => [
                        'impuesto' => [['descripcionImpuesto' => 'IVA RESPONSABLE INSCRIPTO']],
                    ],
                ],
            ],
        ]));
    }

    /** A13 real (research.md R1 de spec 047): nunca trae datosRegimenGeneral/datosMonotributo. */
    private function respuestaPadronSinCondicionIva(): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    ],
                    'estadoClave' => 'ACTIVO',
                ],
            ],
        ]));
    }

    private function respuestaConstanciaResponsableInscripto(): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'datosGenerales' => ['razonSocial' => 'ACME SA'],
                'datosRegimenGeneral' => [
                    'impuesto' => [
                        ['idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC'],
                    ],
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
        $this->mockearClienteConstancia(fn () => $this->respuestaConstanciaResponsableInscripto());

        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('padron.consultado', true);
        $resp->assertJsonPath('padron.encontrado', true);
        $resp->assertJsonPath('padron.razon_social', 'ACME SA');
        $resp->assertJsonPath('padron.domicilio_fiscal', 'AV CORRIENTES 1234');
        $resp->assertJsonPath('padron.localidad_fiscal', 'CABA');
        $resp->assertJsonPath('padron.condicion_iva', 'Responsable Inscripto');
    }

    /** Padrón sin condición de IVA: el resto de los datos se completa igual (FR-004). */
    public function test_padron_sin_condicion_de_iva_completa_el_resto(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => $this->respuestaPadronSinCondicionIva());
        $this->mockearClienteConstancia(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('padron.consultado', true);
        $resp->assertJsonPath('padron.encontrado', true);
        $resp->assertJsonPath('padron.razon_social', 'ACME SA');
        $resp->assertJsonPath('padron.domicilio_fiscal', 'AV CORRIENTES 1234');
        $resp->assertJsonMissingPath('padron.condicion_iva');
    }

    public function test_documento_no_cuit_no_aplica_y_no_consulta_arca(): void
    {
        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=DNI&numero=30123456');

        $resp->assertOk();
        $resp->assertJson(['aplica' => false]);
        $resp->assertJsonMissing(['padron' => []]);
    }

    public function test_padron_no_encuentra_el_cuit(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => json_decode(json_encode(['personaReturn' => null])));

        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('padron.consultado', true);
        $resp->assertJsonPath('padron.encontrado', false);
        $resp->assertJsonPath('padron.mensaje', 'No se encontró el CUIT en el padrón de ARCA.');
    }

    public function test_sin_certificado_activo_no_bloquea_la_respuesta(): void
    {
        CertificadoFiscal::query()->update(['activo' => false]);

        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('valido', true);
        $resp->assertJsonPath('padron.consultado', false);
        $resp->assertJsonPath('padron.mensaje', 'No se pudo consultar el padrón de ARCA en este momento.');
    }

    public function test_arca_no_disponible_no_bloquea_la_respuesta(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111112');

        $resp->assertOk();
        $resp->assertJsonPath('valido', true);
        $resp->assertJsonPath('padron.consultado', false);
        $resp->assertJsonPath('padron.mensaje', 'No se pudo consultar el padrón de ARCA en este momento.');
    }

    /** CUIT con dígito verificador inválido: no debe invocar ningún servicio de ARCA (FR-002). */
    public function test_cuit_invalido_no_consulta_arca(): void
    {
        $resp = $this->getJson('/proveedores/verificar-documento?tipo_documento=CUIT&numero=20111111111');

        $resp->assertOk();
        $resp->assertJsonPath('valido', false);
        $resp->assertJsonMissing(['padron' => []]);
        $resp->assertJsonMissingPath('padron');
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

    private function mockearClienteConstancia(\Closure $respuestaOControlador): void
    {
        $this->app->bind(ClienteConstanciaInscripcion::class, fn () => new class($respuestaOControlador) extends ClienteConstanciaInscripcion
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
