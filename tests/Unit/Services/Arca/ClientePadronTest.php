<?php

namespace Tests\Unit\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SoapClient;
use SoapFault;
use Tests\TestCase;

/** T005 (spec 037): resiliencia del wrapper SOAP de ws_sr_padron_a13. */
class ClientePadronTest extends TestCase
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

    private function clientePadronCon(object $soapClientMock): ClientePadron
    {
        return new class($this->certificado(), $soapClientMock) extends ClientePadron
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

    public function test_respuesta_exitosa_del_padron(): void
    {
        $soap = $this->getMockBuilder(SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPersona'])
            ->getMock();
        $soap->expects($this->once())->method('getPersona')->willReturn((object) ['ok' => true]);

        $resultado = $this->clientePadronCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');

        $this->assertTrue($resultado->ok);
    }

    public function test_cuit_no_encontrado_no_lanza_excepcion(): void
    {
        $soap = $this->getMockBuilder(SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPersona'])
            ->getMock();
        $soap->method('getPersona')->willReturn((object) ['personaReturn' => null]);

        $resultado = $this->clientePadronCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');

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

        $this->clientePadronCon($soap)->consultarConstancia(['token' => 't', 'sign' => 's'], '20304050607');
    }
}
