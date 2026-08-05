<?php

namespace Tests\Unit\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\ClienteConstanciaInscripcion;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SoapClient;
use SoapFault;
use Tests\TestCase;

/** T004 (spec 047): resiliencia del wrapper SOAP de ws_sr_constancia_inscripcion. */
class ClienteConstanciaInscripcionTest extends TestCase
{
    use RefreshDatabase;

    private function certificado(): CertificadoFiscal
    {
        return CertificadoFiscal::create([
            'cuit' => '20111111112',
            'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt',
            'ruta_clave_privada' => 'arca/test.key',
            'activo' => true,
        ]);
    }

    private function clienteConstanciaCon(object $soapClientMock): ClienteConstanciaInscripcion
    {
        return new class($this->certificado(), $soapClientMock) extends ClienteConstanciaInscripcion
        {
            public function __construct(CertificadoFiscal $certificado, private readonly object $mock)
            {
                parent::__construct($certificado);
            }

            protected function crearSoapClient(): SoapClient
            {
                return $this->mock;
            }
        };
    }

    public function test_respuesta_exitosa_con_datos_regimen_general(): void
    {
        $soap = $this->getMockBuilder(SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPersona'])
            ->getMock();
        $soap->expects($this->once())->method('getPersona')->willReturn(json_decode(json_encode([
            'personaReturn' => [
                'datosGenerales' => ['razonSocial' => 'ACME SA'],
                'datosRegimenGeneral' => [
                    'impuesto' => [
                        ['idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC'],
                    ],
                ],
            ],
        ])));

        $resultado = $this->clienteConstanciaCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');

        $this->assertSame('ACME SA', $resultado->personaReturn->datosGenerales->razonSocial);
    }

    public function test_cuit_no_encontrado_no_lanza_excepcion(): void
    {
        $soap = $this->getMockBuilder(SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPersona'])
            ->getMock();
        $soap->method('getPersona')->willReturn((object) ['personaReturn' => null]);

        $resultado = $this->clienteConstanciaCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');

        $this->assertNull($resultado->personaReturn);
    }

    public function test_soap_fault_se_convierte_en_arca_no_disponible_exception(): void
    {
        $soap = $this->getMockBuilder(SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPersona'])
            ->getMock();
        $soap->method('getPersona')->willThrowException(new SoapFault('Server', 'timeout'));

        $this->expectException(ArcaNoDisponibleException::class);

        $this->clienteConstanciaCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');
    }
}
