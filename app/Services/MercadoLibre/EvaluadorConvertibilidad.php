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
     * @param  bool  $ignorarExcepcionales  spec 066 (FR-013): la persona ya confirmó el estado
     *                                      excepcional, así que se saltean esas guardas y queda
     *                                      sólo el veredicto sobre los DATOS. Sin esto, una
     *                                      conversión forzada volvería con el motivo excepcional
     *                                      y nunca llegaría a revisar si la publicación está
     *                                      vinculada, el producto existe o el cliente es ambiguo
     *                                      — es decir, forzar saltearía silenciosamente todas las
     *                                      validaciones, que es justo lo que la spec prohíbe.
     *                                      Sólo lo pasa ConversorOrdenAVenta en el camino forzado.
     * @return array{0: EstadoConversion, 1: ?MotivoRequiereAtencion, 2: ?string}
     */
    public function evaluar(
        MercadoLibreOrden $orden,
        bool $clienteEsAmbiguo = false,
        bool $ignorarExcepcionales = false,
    ): array {
        if ($orden->estado_orden === EstadoOrden::Cancelada && ! $ignorarExcepcionales) {
            return [EstadoConversion::Cancelada, null, null];
        }

        // Convertida es terminal salvo por la cancelación de arriba (FR-058): una
        // Venta ya creada no se "descrea" ni se reevalúa.
        if ($orden->venta_id) {
            return [EstadoConversion::Convertida, null, null];
        }

        // spec 066 (FR-002/FR-003): los estados excepcionales se evalúan ANTES de la exigencia
        // de "Pagada" y antes de las validaciones de datos. Un reembolso parcial no está
        // "pagado" en el sentido de Mercado Libre, y sin esto caería en PendientePago —
        // invisible para la persona y sin motivo que explique por qué no se convierte.
        //
        // La precedencia (mediación → cancelada → reembolso parcial → alerta de fraude) es la
        // de MotivoExcepcional, compartida con DetectorCancelaciones. La cancelación ya salió
        // arriba con su propio estado, así que acá sólo llegan los otros tres.
        //
        // OJO: esto NO habilita las órdenes pendientes de pago, que siguen cayendo en
        // PendientePago abajo. Son cosas distintas y confundirlas rompería FR-014.
        //
        // La alerta de fraude queda deliberadamente FUERA de este bloque temprano y conserva
        // su posición histórica más abajo: adelantarla cambiaría el estado de las órdenes con
        // alerta que además no están pagadas (hoy PendientePago), y eso no lo pide la spec.
        $motivoExcepcional = MotivoExcepcional::de($orden);

        if (! $ignorarExcepcionales && $motivoExcepcional && $motivoExcepcional !== MotivoRequiereAtencion::AlertaFraude) {
            return [
                EstadoConversion::RequiereAtencion,
                $motivoExcepcional,
                MotivoExcepcional::detalle($motivoExcepcional),
            ];
        }

        // La exigencia de pago la resuelve el conversor en el camino forzado, distinguiendo
        // "cancelada" (forzable) de "pendiente de pago" (nunca forzable). Acá se saltea para
        // no rechazar una cancelada que ya viene confirmada.
        if ($orden->estado_orden !== EstadoOrden::Pagada && ! $ignorarExcepcionales) {
            return [EstadoConversion::PendientePago, null, null];
        }

        if ($orden->tiene_alerta_fraude && ! $ignorarExcepcionales) {
            return [
                EstadoConversion::RequiereAtencion,
                MotivoRequiereAtencion::AlertaFraude,
                MotivoExcepcional::detalle(MotivoRequiereAtencion::AlertaFraude),
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
