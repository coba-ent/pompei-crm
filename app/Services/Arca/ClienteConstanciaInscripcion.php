<?php

namespace App\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Wrapper del SoapClient de ws_sr_constancia_inscripcion (personaServiceA5):
 * mismo patrón que `ClientePadron`, consulta "best effort" independiente de
 * ws_sr_padron_a13 (research.md R2/R5/R6).
 */
class ClienteConstanciaInscripcion
{
    private ?SoapClient $cliente = null;

    public function __construct(private readonly CertificadoFiscal $certificado) {}

    /**
     * @param  array{token: string, sign: string}  $ticketAcceso
     */
    public function consultarConstancia(array $ticketAcceso, string $cuit): object
    {
        return $this->llamar('getPersona', [
            'token' => $ticketAcceso['token'],
            'sign' => $ticketAcceso['sign'],
            'cuitRepresentada' => $this->certificado->cuit,
            'idPersona' => $cuit,
        ]);
    }

    private function llamar(string $metodo, array $parametros): object
    {
        try {
            $cliente = $this->cliente ??= $this->crearSoapClient();

            return $cliente->{$metodo}($parametros);
        } catch (SoapFault|Throwable $e) {
            throw new ArcaNoDisponibleException('No se pudo comunicar con la constancia de inscripción de ARCA ('.$metodo.'): '.$e->getMessage(), previous: $e);
        }
    }

    /** Extraído para poder sobreescribirlo en tests sin abrir una conexión SOAP real. */
    protected function crearSoapClient(): SoapClient
    {
        return new SoapClient(
            config('arca.wsdl.ws_sr_constancia_inscripcion.'.$this->certificado->ambiente),
            [
                'soap_version' => SOAP_1_1,
                'connection_timeout' => 8,
                'exceptions' => true,
                // Mismos servidores de ARCA que WSFEv1/ws_sr_padron_a13: negocian DH con clave débil
                // que OpenSSL 3 rechaza por defecto (SECLEVEL 2).
                'stream_context' => stream_context_create([
                    'ssl' => ['ciphers' => 'DEFAULT@SECLEVEL=1'],
                ]),
            ]
        );
    }
}
