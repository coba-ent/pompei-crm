<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoOrden;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\Integraciones\MercadoLibreOrden;

/**
 * Única definición de "orden en estado excepcional" y de la precedencia entre motivos
 * (spec 066, plan.md §2).
 *
 * Existe porque tres componentes necesitan la misma respuesta: EvaluadorConvertibilidad
 * (para frenar la conversión antes de que ocurra), DetectorCancelaciones (para avisar
 * después de que ocurrió) y el modelo (para que la UI sepa qué mostrar). Que cada uno
 * decidiera el motivo por su cuenta es exactamente cómo se producen las inconsistencias
 * que después nadie puede rastrear.
 *
 * Precedencia: mediación → cancelada → reembolso parcial → alerta de fraude.
 * La mediación va primero porque se lee del pago, no de la orden, y puede convivir con
 * cualquier estado de orden reportado.
 */
class MotivoExcepcional
{
    /**
     * Motivo excepcional de la orden, o null si no está en ninguno de los cuatro estados.
     *
     * `$mediacion` permite forzar el dato desde afuera cuando todavía no se persistió —
     * el detector lo lee del payload crudo durante la sincronización, antes de que la
     * columna `en_mediacion` esté escrita. Por defecto se usa lo persistido en la orden.
     */
    public static function de(MercadoLibreOrden $orden, ?bool $mediacion = null): ?MotivoRequiereAtencion
    {
        if ($mediacion ?? (bool) $orden->en_mediacion) {
            return MotivoRequiereAtencion::OrdenEnMediacion;
        }

        if ($orden->estado_orden === EstadoOrden::Cancelada) {
            return MotivoRequiereAtencion::OrdenCancelada;
        }

        if ($orden->estado_orden === EstadoOrden::ReembolsoParcial) {
            return MotivoRequiereAtencion::OrdenReembolsoParcial;
        }

        if ($orden->tiene_alerta_fraude) {
            return MotivoRequiereAtencion::AlertaFraude;
        }

        return null;
    }

    /** Detalle legible para mostrarle a la persona por qué la orden está frenada. */
    public static function detalle(MotivoRequiereAtencion $motivo): string
    {
        return match ($motivo) {
            MotivoRequiereAtencion::OrdenEnMediacion => 'Hay un reclamo en mediación sobre esta orden; el desenlace todavía no está definido. Convertila a mano sólo si sabés cómo termina.',
            MotivoRequiereAtencion::OrdenCancelada => 'La orden fue cancelada en Mercado Libre. Convertila a mano sólo si corresponde facturarla igual.',
            MotivoRequiereAtencion::OrdenReembolsoParcial => 'Mercado Libre informó un reembolso parcial sobre esta orden. Revisá los importes antes de convertirla.',
            MotivoRequiereAtencion::AlertaFraude => 'Mercado Libre marcó esta orden con alerta de fraude: no debe despacharse ni convertirse.',
            default => $motivo->etiqueta(),
        };
    }
}
