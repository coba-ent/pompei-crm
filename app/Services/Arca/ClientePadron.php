<?php

namespace App\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Wrapper del SoapClient de ws_sr_padron_a13 (consulta general de persona):
 * mismo patrón que `ClienteWsfev1`, un wrapper delgado por WSDL. Consulta
 * "best effort" — timeout más corto (research.md R3) porque es un
 * enriquecimiento opcional, nunca una operación crítica de facturación.
 */
class ClientePadron
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
            throw new ArcaNoDisponibleException('No se pudo comunicar con el padrón de ARCA ('.$metodo.'): '.$e->getMessage(), previous: $e);
        }
    }

    /** Extraído para poder sobreescribirlo en tests sin abrir una conexión SOAP real. */
    protected function crearSoapClient(): SoapClient
    {
        return new SoapClient(
            config('arca.wsdl.ws_sr_padron_a13.'.$this->certificado->ambiente),
            [
                'soap_version' => SOAP_1_2,
                'connection_timeout' => 8,
                'exceptions' => true,
                // Mismos servidores de ARCA que WSFEv1: negocian DH con clave débil
                // que OpenSSL 3 rechaza por defecto (SECLEVEL 2).
                'stream_context' => stream_context_create([
                    'ssl' => ['ciphers' => 'DEFAULT@SECLEVEL=1'],
                ]),
            ]
        );
    }
}
