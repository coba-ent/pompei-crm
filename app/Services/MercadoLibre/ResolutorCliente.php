<?php

namespace App\Services\MercadoLibre;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Integraciones\MercadoLibreOrden;

/**
 * Empareja al comprador de una orden con un Cliente del CRM (FR-036..FR-041,
 * research.md §R12): primero por `ml_user_id` (estable, siempre presente),
 * luego por `apodo_ml` (puente con clientes cargados a mano). El caso
 * ambiguo nunca se resuelve arbitrariamente (FR-038).
 */
class ResolutorCliente
{
    /**
     * @param  array{tipo_comprobante: string, condicion_iva: string, doc_tipo: ?string, doc_numero: ?string, aproximado: bool}  $datosFiscales
     * @return array{cliente: ?Cliente, ambiguo: bool}
     */
    public function resolver(MercadoLibreOrden $orden, array $datosFiscales): array
    {
        ['cliente' => $cliente, 'ambiguo' => $ambiguo] = $this->buscarExistente($orden);

        if ($ambiguo) {
            return ['cliente' => null, 'ambiguo' => true];
        }

        if ($cliente) {
            // FR-036a: si se emparejó por apodo, guardar el id de Mercado Libre para
            // que la próxima vez resuelva por la vía estable.
            if ((string) $cliente->ml_user_id !== (string) $orden->comprador_ml_id) {
                $cliente->update(['ml_user_id' => $orden->comprador_ml_id]);
            }

            $this->completarDatosFiscalesSinPisar($cliente, $datosFiscales);

            return ['cliente' => $cliente->fresh(), 'ambiguo' => false];
        }

        return ['cliente' => $this->crearCliente($orden, $datosFiscales), 'ambiguo' => false];
    }

    /**
     * Igual que resolver(), pero SÓLO busca: no crea el Cliente ni toca ningún
     * dato. La usa la sincronización para saber, sin efectos secundarios, si el
     * comprador ya existe en el CRM y así marcar el aviso no bloqueante
     * `cliente_nuevo` en la orden.
     *
     * @return array{cliente: ?Cliente, ambiguo: bool}
     */
    public function buscarExistente(MercadoLibreOrden $orden): array
    {
        $cliente = Cliente::where('ml_user_id', $orden->comprador_ml_id)->first();

        if (! $cliente && $orden->comprador_apodo) {
            $candidatos = Cliente::where('apodo_ml', $orden->comprador_apodo)->get();

            if ($candidatos->count() > 1) {
                return ['cliente' => null, 'ambiguo' => true];
            }

            $cliente = $candidatos->first();
        }

        return ['cliente' => $cliente, 'ambiguo' => false];
    }

    /** FR-037/FR-040d: alta automática con condición de IVA y comprobante por defecto siempre cargados. */
    private function crearCliente(MercadoLibreOrden $orden, array $datosFiscales): Cliente
    {
        return Cliente::create([
            'nombre' => $orden->comprador_nombre ?: ($orden->comprador_apodo ?: 'Comprador Mercado Libre '.$orden->comprador_ml_id),
            'apodo_ml' => $orden->comprador_apodo,
            'ml_user_id' => $orden->comprador_ml_id,
            'cuit' => $datosFiscales['doc_tipo'] === 'CUIT' ? $datosFiscales['doc_numero'] : null,
            'tipo_documento' => $datosFiscales['doc_tipo'],
            'condicion_iva_id' => $this->condicionIvaId($datosFiscales['condicion_iva']),
            'tipo_comprobante_defecto' => $datosFiscales['tipo_comprobante'],
            'razon_social' => $datosFiscales['razon_social'] ?? null,
            'domicilio_fiscal' => $datosFiscales['domicilio_fiscal'] ?? null,
            'localidad_fiscal' => $datosFiscales['localidad_fiscal'] ?? null,
            'activo' => true,
        ]);
    }

    /** FR-041/FR-041a/FR-007b: completa sólo lo que falta, nunca pisa datos ya cargados a mano. */
    private function completarDatosFiscalesSinPisar(Cliente $cliente, array $datosFiscales): void
    {
        $cambios = [];

        if (empty($cliente->condicion_iva_id)) {
            $cambios['condicion_iva_id'] = $this->condicionIvaId($datosFiscales['condicion_iva']);
        }
        if (empty($cliente->tipo_documento)) {
            $cambios['tipo_documento'] = $datosFiscales['doc_tipo'];
        }
        if (empty($cliente->cuit) && $datosFiscales['doc_tipo'] === 'CUIT') {
            $cambios['cuit'] = $datosFiscales['doc_numero'];
        }
        if (empty($cliente->tipo_comprobante_defecto)) {
            $cambios['tipo_comprobante_defecto'] = $datosFiscales['tipo_comprobante'];
        }
        if (empty($cliente->razon_social) && ! empty($datosFiscales['razon_social'])) {
            $cambios['razon_social'] = $datosFiscales['razon_social'];
        }
        if (empty($cliente->domicilio_fiscal) && ! empty($datosFiscales['domicilio_fiscal'])) {
            $cambios['domicilio_fiscal'] = $datosFiscales['domicilio_fiscal'];
        }
        if (empty($cliente->localidad_fiscal) && ! empty($datosFiscales['localidad_fiscal'])) {
            $cambios['localidad_fiscal'] = $datosFiscales['localidad_fiscal'];
        }

        if ($cambios) {
            $cliente->update($cambios);
        }
    }

    private function condicionIvaId(string $nombreCrm): ?int
    {
        return CondicionIva::where('nombre', $nombreCrm)->value('id');
    }
}
