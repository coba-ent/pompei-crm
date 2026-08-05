<?php

namespace App\Services\Tiendanube;

use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Integraciones\TiendanubeOrden;
use App\Services\Arca\ClienteConstanciaInscripcion;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\ResultadoConsultaPadron;

/**
 * Empareja al comprador de una orden de Tiendanube con un Cliente del CRM
 * (FR-036..FR-041): primero por `tn_customer_id` (estable), luego por
 * `comprador_email`. El caso ambiguo nunca se resuelve arbitrariamente
 * (FR-038). A diferencia de `App\Services\MercadoLibre\ResolutorCliente`, acá
 * la derivación del tipo de comprobante vive en la misma clase (plan.md §4):
 * Tiendanube no expone una condición de IVA propia que justifique un servicio
 * de derivación separado — sólo un documento crudo (`cpf_cnpj`), y la fuente
 * primaria es la condición de IVA que el Cliente ya tenga cargada (FR-039).
 */
class ResolutorCliente
{
    /** Mismo mapeo que `App\Services\MercadoLibre\ResolutorCliente`: única condición que deriva Factura A. */
    private const CONDICION_IVA_FACTURA_A = 'Responsable Inscripto';

    private const CONDICION_IVA_DEFECTO = 'Consumidor Final';

    /** Resultado de la última consulta al padrón hecha por tipoComprobante(), reusado por completarDatosFiscalesSinPisar() (FR-007b). */
    private ?ResultadoConsultaPadron $ultimaConsultaPadron = null;

    /**
     * @return array{cliente: ?Cliente, ambiguo: bool, tipo_comprobante: string, aproximado: bool}
     */
    public function resolver(TiendanubeOrden $orden): array
    {
        ['cliente' => $cliente, 'ambiguo' => $ambiguo] = $this->buscarExistente($orden);

        if ($ambiguo) {
            return ['cliente' => null, 'ambiguo' => true, 'tipo_comprobante' => 'B', 'aproximado' => true];
        }

        if ($cliente) {
            // El tipo de comprobante se deriva ANTES de completar datos fiscales
            // (FR-039): si se calculara después, un Cliente nuevo recién
            // completado con "Consumidor Final" (más abajo) haría que la fuente
            // primaria "ya tenga condición cargada" ganara siempre, tapando la
            // aproximación por documento con el propio valor que este método
            // acaba de asignarle un instante antes.
            $teniaCondicionIva = (bool) $cliente->condicion_iva_id;
            $tipoComprobante = $this->tipoComprobante($cliente, $orden);

            // FR-036a: si se emparejó por email, guardar el id de Tiendanube para
            // que la próxima vez resuelva por la vía estable.
            if ((string) $cliente->tn_customer_id !== (string) $orden->tn_customer_id) {
                $cliente->update(['tn_customer_id' => $orden->tn_customer_id]);
            }

            $this->completarDatosFiscalesSinPisar($cliente, $orden, $tipoComprobante);

            return [
                'cliente' => $cliente->fresh(), 'ambiguo' => false,
                'tipo_comprobante' => $tipoComprobante, 'aproximado' => ! $teniaCondicionIva,
            ];
        }

        $cliente = $this->crearCliente($orden);

        return [
            'cliente' => $cliente, 'ambiguo' => false,
            'tipo_comprobante' => $cliente->tipo_comprobante_defecto, 'aproximado' => true,
        ];
    }

    /**
     * Igual que resolver(), pero SÓLO busca: no crea el Cliente ni toca ningún
     * dato. La usa `SincronizadorOrdenes`/`EvaluadorConvertibilidad` para saber,
     * sin efectos secundarios, si el comprador ya existe y si es ambiguo.
     *
     * @return array{cliente: ?Cliente, ambiguo: bool}
     */
    public function buscarExistente(TiendanubeOrden $orden): array
    {
        $cliente = $orden->tn_customer_id
            ? Cliente::where('tn_customer_id', $orden->tn_customer_id)->first()
            : null;

        if (! $cliente && $orden->comprador_email) {
            $candidatos = Cliente::where('email', $orden->comprador_email)->get();

            if ($candidatos->count() > 1) {
                return ['cliente' => null, 'ambiguo' => true];
            }

            $cliente = $candidatos->first();
        }

        return ['cliente' => $cliente, 'ambiguo' => false];
    }

    /**
     * Tipo de comprobante para la orden (FR-039/FR-040): primero la condición
     * de IVA que el Cliente emparejado ya tenga cargada; sólo si es nuevo o no
     * la tiene, se aproxima por longitud de `billing_document_number`
     * (`cpf_cnpj`, corrección post-019 — no existe `billing_document_type`).
     */
    public function tipoComprobante(?Cliente $cliente, TiendanubeOrden $orden): string
    {
        $this->ultimaConsultaPadron = null;

        if ($cliente && $cliente->condicion_iva_id) {
            return $this->tipoComprobantePorCondicionIva($cliente->condicion_iva_id);
        }

        $resultadoPadron = $this->consultarPadron($orden->billing_document_number);

        if ($resultadoPadron && $resultadoPadron->condicionIvaId) {
            $this->ultimaConsultaPadron = $resultadoPadron;

            return $this->tipoComprobantePorCondicionIva($resultadoPadron->condicionIvaId);
        }

        return $this->tipoComprobantePorDocumento($orden->billing_document_number);
    }

    /**
     * Consulta ws_sr_padron_a13 cuando el documento de la orden tiene forma de
     * CUIT (research.md R4). Best effort: cualquier falla degrada a `null` sin
     * propagar excepción (FR-008/FR-009, Constitución III).
     */
    private function consultarPadron(?string $documento): ?ResultadoConsultaPadron
    {
        $cuit = $documento ? preg_replace('/\D/', '', $documento) : '';

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

    /** FR-037/FR-040d: alta automática con condición de IVA y comprobante por defecto siempre cargados. */
    private function crearCliente(TiendanubeOrden $orden): Cliente
    {
        $tipoComprobante = $this->tipoComprobante(null, $orden);
        $resultadoPadron = $this->ultimaConsultaPadron;

        return Cliente::create([
            'nombre' => $orden->comprador_nombre ?: ('Comprador Tiendanube '.$orden->tn_customer_id),
            'email' => $orden->comprador_email,
            'tn_customer_id' => $orden->tn_customer_id,
            'condicion_iva_id' => $resultadoPadron?->condicionIvaId ?: CondicionIva::where('nombre', self::CONDICION_IVA_DEFECTO)->value('id'),
            'tipo_comprobante_defecto' => $tipoComprobante,
            'razon_social' => $resultadoPadron?->razonSocial,
            'domicilio_fiscal' => $resultadoPadron?->domicilioFiscal,
            'localidad_fiscal' => $resultadoPadron?->localidadFiscal,
            'provincia_fiscal' => $resultadoPadron?->provinciaFiscal,
            'activo' => true,
        ]);
    }

    /** FR-041/FR-041a/FR-007b: completa sólo lo que falta, nunca pisa datos ya cargados a mano. */
    private function completarDatosFiscalesSinPisar(Cliente $cliente, TiendanubeOrden $orden, string $tipoComprobanteYaDerivado): void
    {
        unset($orden);

        $resultadoPadron = $this->ultimaConsultaPadron;

        if (empty($cliente->condicion_iva_id)) {
            $cliente->update([
                'condicion_iva_id' => $resultadoPadron?->condicionIvaId ?: CondicionIva::where('nombre', self::CONDICION_IVA_DEFECTO)->value('id'),
                'tipo_comprobante_defecto' => $cliente->tipo_comprobante_defecto ?: $tipoComprobanteYaDerivado,
                'razon_social' => $cliente->razon_social ?: $resultadoPadron?->razonSocial,
                'domicilio_fiscal' => $cliente->domicilio_fiscal ?: $resultadoPadron?->domicilioFiscal,
                'localidad_fiscal' => $cliente->localidad_fiscal ?: $resultadoPadron?->localidadFiscal,
                'provincia_fiscal' => $cliente->provincia_fiscal ?: $resultadoPadron?->provinciaFiscal,
            ]);
        }
    }

    /**
     * FR-040 (corrección post-019): aproximación por longitud del documento
     * crudo — 11 dígitos (CUIT) → A; 7-8 dígitos, otra longitud o ausente → B
     * (caso dominante en la práctica, verificado vacío en las 9 órdenes reales
     * de la tienda).
     */
    private function tipoComprobantePorDocumento(?string $documento): string
    {
        $soloDigitos = $documento ? preg_replace('/\D/', '', $documento) : '';

        return strlen($soloDigitos) === 11 ? 'A' : 'B';
    }

    private function tipoComprobantePorCondicionIva(int $condicionIvaId): string
    {
        $nombre = CondicionIva::find($condicionIvaId)?->nombre;

        return $nombre === self::CONDICION_IVA_FACTURA_A ? 'A' : 'B';
    }
}
