<?php

namespace App\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use Illuminate\Support\Facades\Cache;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Autenticación WSAA (Web Service de Autenticación y Autorización): genera el TRA, lo firma en
 * CMS con el certificado del negocio, y cachea el Ticket de Acceso resultante hasta su expiración.
 */
class ClienteWsaa
{
    public function __construct(private readonly CertificadoFiscal $certificado) {}

    /**
     * @return array{token: string, sign: string}
     */
    public function obtenerTicketAcceso(string $servicio = 'wsfe'): array
    {
        $clave = 'arca.ta.'.$servicio.'.'.$this->certificado->id;

        return Cache::remember($clave, now()->addHours(11), function () use ($servicio) {
            return $this->autenticar($servicio);
        });
    }

    /**
     * @return array{token: string, sign: string}
     */
    private function autenticar(string $servicio): array
    {
        try {
            $tra = $this->generarTra($servicio);
            $cms = $this->firmarCms($tra);

            $wsdl = config('arca.wsdl.wsaa.'.$this->certificado->ambiente);
            $cliente = new SoapClient($wsdl, ['soap_version' => SOAP_1_2, 'connection_timeout' => 15]);
            $respuesta = $cliente->loginCms(['in0' => $cms]);

            $xml = simplexml_load_string($respuesta->loginCmsReturn);

            return [
                'token' => (string) $xml->credentials->token,
                'sign' => (string) $xml->credentials->sign,
            ];
        } catch (SoapFault|Throwable $e) {
            throw new ArcaNoDisponibleException('No se pudo autenticar contra WSAA: '.$e->getMessage(), previous: $e);
        }
    }

    private function generarTra(string $servicio): string
    {
        $uniqueId = time();
        $generationTime = date('c', time() - 60);
        $expirationTime = date('c', time() + 600);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<loginTicketRequest version="1.0">
    <header>
        <uniqueId>{$uniqueId}</uniqueId>
        <generationTime>{$generationTime}</generationTime>
        <expirationTime>{$expirationTime}</expirationTime>
    </header>
    <service>{$servicio}</service>
</loginTicketRequest>
XML;
    }

    private function firmarCms(string $tra): string
    {
        $certificado = $this->certificado->leerCertificado();
        $clavePrivada = $this->certificado->leerClavePrivada();

        $traTmp = tempnam(sys_get_temp_dir(), 'arca_tra_');
        $cmsTmp = tempnam(sys_get_temp_dir(), 'arca_cms_');
        file_put_contents($traTmp, $tra);

        try {
            $firmado = openssl_pkcs7_sign(
                $traTmp,
                $cmsTmp,
                $certificado,
                $clavePrivada,
                [],
                PKCS7_BINARY | PKCS7_NOATTR
            );

            if (! $firmado) {
                throw new ArcaNoDisponibleException('No se pudo firmar el TRA con el certificado configurado.');
            }

            $contenido = file_get_contents($cmsTmp);
            $partes = explode("\n\n", $contenido, 2);

            return trim(str_replace("\n", '', $partes[1] ?? ''));
        } finally {
            @unlink($traTmp);
            @unlink($cmsTmp);
        }
    }
}
