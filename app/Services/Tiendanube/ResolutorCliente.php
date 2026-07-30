<?php

namespace App\Services\Tiendanube;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Integraciones\TiendanubeOrden;

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
        if ($cliente && $cliente->condicion_iva_id) {
            return $this->tipoComprobantePorCondicionIva($cliente->condicion_iva_id);
        }

        return $this->tipoComprobantePorDocumento($orden->billing_document_number);
    }

    /** FR-037/FR-040d: alta automática con condición de IVA y comprobante por defecto siempre cargados. */
    private function crearCliente(TiendanubeOrden $orden): Cliente
    {
        $tipoComprobante = $this->tipoComprobantePorDocumento($orden->billing_document_number);

        return Cliente::create([
            'nombre' => $orden->comprador_nombre ?: ('Comprador Tiendanube '.$orden->tn_customer_id),
            'email' => $orden->comprador_email,
            'tn_customer_id' => $orden->tn_customer_id,
            'condicion_iva_id' => CondicionIva::where('nombre', self::CONDICION_IVA_DEFECTO)->value('id'),
            'tipo_comprobante_defecto' => $tipoComprobante,
            'activo' => true,
        ]);
    }

    /** FR-041/FR-041a: completa sólo lo que falta, nunca pisa datos ya cargados a mano. */
    private function completarDatosFiscalesSinPisar(Cliente $cliente, TiendanubeOrden $orden, string $tipoComprobanteYaDerivado): void
    {
        unset($orden);

        if (empty($cliente->condicion_iva_id)) {
            $cliente->update([
                'condicion_iva_id' => CondicionIva::where('nombre', self::CONDICION_IVA_DEFECTO)->value('id'),
                'tipo_comprobante_defecto' => $cliente->tipo_comprobante_defecto ?: $tipoComprobanteYaDerivado,
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
