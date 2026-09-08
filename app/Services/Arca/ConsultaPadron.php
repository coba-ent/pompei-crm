<?php

namespace App\Services\Arca;

use App\Models\CertificadoFiscal;
use App\Models\CondicionIva;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;

/**
 * Servicio compartido que centraliza la consulta al padrón de ARCA (research.md
 * R1/R2 de spec 100): hasta esta extracción la secuencia estaba triplicada en
 * `DerivadorComprobante` (Mercado Libre), `ResolutorCliente` (Tiendanube) y
 * `ClienteController`. Es una extracción sin cambio de comportamiento: la
 * normalización, los guards y el manejo de errores se conservan idénticos.
 */
class ConsultaPadron
{
    /**
     * Consulta ws_sr_padron_a13 (y, best effort, ws_sr_constancia_inscripcion).
     * Degrada a `null` ante cualquier falla, sin propagar excepciones
     * (Constitución III): certificado ausente, CUIT mal formado o ARCA no
     * disponible.
     */
    public function consultar(?string $cuit): ?ResultadoConsultaPadron
    {
        $cuit = $cuit ? preg_replace('/\D/', '', $cuit) : '';

        if (strlen($cuit) !== 11) {
            return null;
        }

        $certificado = CertificadoFiscal::activo();

        if (! $certificado) {
            return null;
        }

        try {
            $ticketAcceso = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_padron_a13');
            $respuesta = app()->makeWith(ClientePadron::class, ['certificado' => $certificado])->consultarConstancia($ticketAcceso, $cuit);

            $resultado = ResultadoConsultaPadron::desdeRespuesta($cuit, $respuesta);
        } catch (ArcaNoDisponibleException) {
            return null;
        }

        // Consulta independiente y best-effort a ws_sr_constancia_inscripcion (research.md R5 de spec 047).
        try {
            $ticketConstancia = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_constancia_inscripcion');
            $respuestaConstancia = app()->makeWith(ClienteConstanciaInscripcion::class, ['certificado' => $certificado])->consultarConstancia($ticketConstancia, $cuit);

            return ResultadoConsultaPadron::conCondicionIva($resultado, $respuestaConstancia);
        } catch (ArcaNoDisponibleException) {
            return $resultado;
        }
    }

    /**
     * Traduce el resultado de consultar() a la estructura JSON que consumen los
     * modales de Cliente y Proveedor (research.md R2), garantizando mensajes
     * idénticos entre ambos (FR-006 de spec 100).
     *
     * @return array<string, mixed>
     */
    public function paraModal(string $cuit): array
    {
        $certificado = CertificadoFiscal::activo();

        if (! $certificado) {
            return ['consultado' => false, 'mensaje' => 'No se pudo consultar el padrón de ARCA en este momento.'];
        }

        $cuitNormalizado = preg_replace('/\D/', '', $cuit);

        try {
            $ticketAcceso = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_padron_a13');
            $respuesta = app()->makeWith(ClientePadron::class, ['certificado' => $certificado])->consultarConstancia($ticketAcceso, $cuitNormalizado);
            $resultado = ResultadoConsultaPadron::desdeRespuesta($cuitNormalizado, $respuesta);
        } catch (ArcaNoDisponibleException) {
            return ['consultado' => false, 'mensaje' => 'No se pudo consultar el padrón de ARCA en este momento.'];
        }

        if (! $resultado->encontrado) {
            return ['consultado' => true, 'encontrado' => false, 'mensaje' => 'No se encontró el CUIT en el padrón de ARCA.'];
        }

        // Consulta independiente y best-effort a ws_sr_constancia_inscripcion (research.md R5 de spec 047):
        // su éxito o fracaso no condiciona el resto de los datos ya resueltos por A13.
        try {
            $ticketConstancia = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_constancia_inscripcion');
            $respuestaConstancia = app()->makeWith(ClienteConstanciaInscripcion::class, ['certificado' => $certificado])->consultarConstancia($ticketConstancia, $cuitNormalizado);
            $resultado = ResultadoConsultaPadron::conCondicionIva($resultado, $respuestaConstancia);
        } catch (ArcaNoDisponibleException) {
            // Sin efecto: la condición de IVA queda ausente, razón social/domicilio ya resueltos por A13.
        }

        return array_filter([
            'consultado' => true,
            'encontrado' => true,
            'razon_social' => $resultado->razonSocial,
            'domicilio_fiscal' => $resultado->domicilioFiscal,
            'localidad_fiscal' => $resultado->localidadFiscal,
            'provincia_fiscal' => $resultado->provinciaFiscal,
            'condicion_iva' => $resultado->condicionIvaId ? CondicionIva::find($resultado->condicionIvaId)?->nombre : null,
            'activo' => $resultado->activo,
        ], fn ($valor) => $valor !== null);
    }
}
