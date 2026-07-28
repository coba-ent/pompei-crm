<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\EstadoOrden;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;

/**
 * Único punto que deriva el estado de conversión de una orden (FR-007a,
 * plan.md §3): lo usa SincronizadorOrdenes en cada corrida y
 * ConversorOrdenAVenta en cada intento de conversión, para no duplicar la
 * lógica de qué hace a una orden "Lista para convertir".
 */
class EvaluadorConvertibilidad
{
    /**
     * @return array{0: EstadoConversion, 1: ?MotivoRequiereAtencion, 2: ?string}
     */
    public function evaluar(MercadoLibreOrden $orden, bool $clienteEsAmbiguo = false): array
    {
        if ($orden->estado_orden === EstadoOrden::Cancelada) {
            return [EstadoConversion::Cancelada, null, null];
        }

        // Convertida es terminal salvo por la cancelación de arriba (FR-058): una
        // Venta ya creada no se "descrea" ni se reevalúa.
        if ($orden->venta_id) {
            return [EstadoConversion::Convertida, null, null];
        }

        if ($orden->estado_orden !== EstadoOrden::Pagada) {
            return [EstadoConversion::PendientePago, null, null];
        }

        if ($orden->tiene_alerta_fraude) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::AlertaFraude,
                'Mercado Libre marcó esta orden con alerta de fraude: no debe despacharse ni convertirse.',
            ];
        }

        if ($orden->moneda !== TraductorOrdenes::MONEDA_NEGOCIO) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::MonedaInvalida,
                "La orden está en {$orden->moneda}, distinta de la moneda del negocio.",
            ];
        }

        $items = $orden->relationLoaded('items') ? $orden->items : $orden->items()->get();

        foreach ($items as $item) {
            if ($item->tieneVariante()) {
                return [
                    EstadoConversion::RequiereAtencion,
                    MotivoRequiereAtencion::PublicacionConVariantes,
                    "La publicación {$item->ml_item_id} tiene variantes; no soportado.",
                ];
            }
        }

        foreach ($items as $item) {
            $motivo = $this->motivoPorPublicacion($item);
            if ($motivo) {
                return [EstadoConversion::RequiereAtencion, $motivo[0], $motivo[1]];
            }
        }

        if ($clienteEsAmbiguo) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::ClienteAmbiguo,
                'Hay más de un Cliente del CRM con el mismo apodo de Mercado Libre. Corregí los apodos duplicados.',
            ];
        }

        if (filled($orden->datos_faltantes) && str_contains($orden->datos_faltantes, 'buyer')) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::DatosIncompletos,
                'Mercado Libre no envió todavía los datos del comprador. Volvé a sincronizar.',
            ];
        }

        return [EstadoConversion::Lista, null, null];
    }

    /**
     * @return array{0: MotivoRequiereAtencion, 1: string}|null
     */
    private function motivoPorPublicacion(MercadoLibreOrdenItem $item): ?array
    {
        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', $item->ml_item_id)->first();

        if (! $vinculo) {
            return [
                MotivoRequiereAtencion::PublicacionSinVincular,
                "La publicación {$item->ml_item_id} ({$item->titulo}) no está vinculada a ningún producto.",
            ];
        }

        if (! $vinculo->producto || ! $vinculo->producto->activo) {
            return [
                MotivoRequiereAtencion::ProductoInexistente,
                "El producto vinculado a la publicación {$item->ml_item_id} fue eliminado o inactivado.",
            ];
        }

        return null;
    }
}
