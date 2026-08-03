<?php

namespace App\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use SoapClient;
use SoapFault;
use Throwable;

/** Wrapper del SoapClient de WSFEv1: solicitud de CAE y consultas. */
class ClienteWsfev1
{
    private ?SoapClient $cliente = null;

    public function __construct(private readonly CertificadoFiscal $certificado) {}

    /**
     * @param  array{token: string, sign: string}  $ticketAcceso
     */
    public function solicitarCae(array $ticketAcceso, array $comprobante): object
    {
        return $this->llamar('FECAESolicitar', [
            'Auth' => $this->auth($ticketAcceso),
            'FeCAEReq' => $comprobante,
        ]);
    }

    /**
     * @param  array{token: string, sign: string}  $ticketAcceso
     */
    public function consultarUltimoAutorizado(array $ticketAcceso, int $puntoVenta, string $tipoComprobante): object
    {
        return $this->llamar('FECompUltimoAutorizado', [
            'Auth' => $this->auth($ticketAcceso),
            'PtoVta' => $puntoVenta,
            'CbteTipo' => $tipoComprobante,
        ]);
    }

    /**
     * @param  array{token: string, sign: string}  $ticketAcceso
     */
    public function consultarComprobante(array $ticketAcceso, int $puntoVenta, string $tipoComprobante, int $numero): object
    {
        return $this->llamar('FECompConsultar', [
            'Auth' => $this->auth($ticketAcceso),
            'FeCompConsReq' => [
                'CbteTipo' => $tipoComprobante,
                'CbteNro' => $numero,
                'PtoVta' => $puntoVenta,
            ],
        ]);
    }

    private function auth(array $ticketAcceso): array
    {
        return [
            'Token' => $ticketAcceso['token'],
            'Sign' => $ticketAcceso['sign'],
            'Cuit' => $this->certificado->cuit,
        ];
    }

    private function llamar(string $metodo, array $parametros): object
    {
        try {
            $cliente = $this->cliente ??= new SoapClient(
                config('arca.wsdl.wsfev1.'.$this->certificado->ambiente),
                [
                    'soap_version' => SOAP_1_2,
                    'connection_timeout' => 15,
                    'exceptions' => true,
                    // Los servidores de AFIP/WSFEv1 negocian DH con una clave débil que OpenSSL 3
                    // rechaza por defecto (SECLEVEL 2); se baja a SECLEVEL 1 sólo para esta conexión.
                    'stream_context' => stream_context_create([
                        'ssl' => ['ciphers' => 'DEFAULT@SECLEVEL=1'],
                    ]),
                ]
            );

            return $cliente->{$metodo}($parametros);
        } catch (SoapFault|Throwable $e) {
            throw new ArcaNoDisponibleException('No se pudo comunicar con WSFEv1 ('.$metodo.'): '.$e->getMessage(), previous: $e);
        }
    }
}
