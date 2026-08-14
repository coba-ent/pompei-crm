<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\Integraciones\MercadoLibreOrden;
use Carbon\Carbon;

/**
 * Decide si una orden YA convertida en Venta debe marcarse (o dejar de
 * marcarse) por una cancelación, reembolso parcial o mediación posteriores
 * (spec 063). Regla aislada del sincronizador porque tiene tres variantes y
 * condiciones de cierre (plan.md §"Enfoque técnico").
 *
 * NO escribe en la base: `decidir()` es una función pura que devuelve la
 * terna [estado, motivo, detalle] final, para que `SincronizadorOrdenes`
 * la aplique en el mismo `update()` que ya iba a hacer con lo que devolvió
 * `EvaluadorConvertibilidad` — evita una segunda escritura y, sobre todo,
 * evita que la propia marca se pise a sí misma en cada re-sincronización
 * (evaluador siempre devuelve motivo=null para una orden cancelada, sin
 * mirar si ya estaba marcada por este detector).
 *
 * NO modifica importes, cobros, Tesorería ni stock (FR-003). No hay tabla
 * nueva: el aviso ES la orden marcada `requiere_atencion` (data-model.md
 * §"Regla de identidad").
 *
 * No hay columna propia para "fecha de detección" (data-model.md: sin
 * cambios de esquema en `ml_ordenes`), así que se codifica dentro de
 * `motivo_detalle` con un formato fijo y se recupera por parseo al
 * re-sincronizar, para no perderla (FR-005) ni al transicionar de motivo
 * (US3 escenario 3).
 */
class DetectorCancelaciones
{
    private const FORMATO_FECHA = 'Y-m-d H:i:s';

    public function __construct(private readonly TraductorOrdenes $traductor)
    {
    }

    /**
     * @param  array<string, mixed>  $ordenCruda
     * @return array{0: EstadoConversion, 1: ?MotivoRequiereAtencion, 2: ?string}|null
     *         Null = no corresponde pisar la decisión de EvaluadorConvertibilidad.
     */
    public function decidir(
        MercadoLibreOrden $orden,
        array $ordenCruda,
        ?MotivoRequiereAtencion $motivoPrevio,
        ?string $motivoDetallePrevio,
        EstadoConversion $estadoConversionPrevio,
    ): ?array {
        if (! $orden->venta_id) {
            return null; // FR-007: nunca se convirtió, no hay Venta que avisar.
        }

        $venta = $orden->venta()->withTrashed()->first();

        if (! $venta || $venta->trashed()) {
            return null; // Venta ya eliminada: se deja la decisión de evaluador (research.md §R1/FR-007).
        }

        if ($venta->total > 0 && $venta->totalNotasCredito() >= $venta->total) {
            return null; // FR-010a: ya se resolvió con una nota de crédito que la compensa; no se re-marca.
        }

        $motivo = $this->determinarMotivo($orden, $ordenCruda);

        // spec 066 (FR-018): si el motivo detectado es exactamente el que la persona ya
        // asumió al forzar la conversión, no hay nada nuevo que avisar — avisarlo sería
        // devolverle como novedad la decisión que acaba de tomar. La comparación es contra
        // el motivo forzado siempre, sin importar por qué motivos haya pasado en el medio
        // (Edge Case de la spec): un motivo distinto sí avisa (FR-019).
        //
        // OJO: se devuelve [Convertida, null, null] explícito, NO null. Devolver null acá
        // haría que SincronizadorOrdenes usara el estado crudo de EvaluadorConvertibilidad,
        // que para una orden cancelada es "Cancelada" aunque tenga Venta (ese caso normalmente
        // lo corrige este mismo método devolviendo RequiereAtencion) — sin este retorno
        // explícito, una orden forzada revertiría a "Cancelada" perdiendo su condición de
        // convertida cada vez que se re-sincroniza.
        if ($motivo !== null && $orden->forzada_motivo === $motivo->value) {
            return [EstadoConversion::Convertida, null, null];
        }

        $yaMarcada = $estadoConversionPrevio === EstadoConversion::RequiereAtencion
            && in_array($motivoPrevio, MotivoRequiereAtencion::motivosDeCancelacionPosterior(), true);

        if ($motivo === null) {
            if ($yaMarcada) {
                return [EstadoConversion::Convertida, null, null]; // FR-006: la orden volvió a un estado vigente.
            }

            return null;
        }

        if ($yaMarcada && $motivoPrevio === $motivo) {
            // FR-005: idempotencia — mismo motivo, no se toca nada (ni siquiera la fecha).
            return [EstadoConversion::RequiereAtencion, $motivo, $motivoDetallePrevio];
        }

        $fechaDeteccion = $yaMarcada
            ? ($this->extraerFechaDeteccion($motivoDetallePrevio) ?? now()) // US3 escenario 3: conserva la fecha original al transicionar de motivo.
            : now();

        return [
            EstadoConversion::RequiereAtencion,
            $motivo,
            $this->construirDetalle($motivo, $orden, $ordenCruda, $fechaDeteccion),
        ];
    }

    /**
     * spec 066 (T004): la precedencia mediación → cancelada → reembolso parcial vive en
     * MotivoExcepcional, compartida con EvaluadorConvertibilidad. Acá se lee la mediación
     * del payload crudo porque durante la sincronización la columna `en_mediacion` puede
     * no estar escrita todavía.
     *
     * La alerta de fraude que MotivoExcepcional también contempla no aplica a este
     * detector: es un aviso PREVIO a convertir, no una cancelación posterior (spec 063).
     */
    private function determinarMotivo(MercadoLibreOrden $orden, array $ordenCruda): ?MotivoRequiereAtencion
    {
        $motivo = MotivoExcepcional::de($orden, $this->traductor->tieneMediacion($ordenCruda));

        return in_array($motivo, MotivoRequiereAtencion::motivosDeCancelacionPosterior(), true)
            ? $motivo
            : null;
    }

    private function construirDetalle(
        MotivoRequiereAtencion $motivo,
        MercadoLibreOrden $orden,
        array $ordenCruda,
        Carbon $fechaDeteccion
    ): string {
        $partes = [
            $motivo->etiqueta(),
            "Estado informado por Mercado Libre: {$orden->estado_ml}.",
        ];

        if ($motivo === MotivoRequiereAtencion::OrdenReembolsoParcial) {
            $importe = $this->traductor->importeReembolsado($ordenCruda);
            $partes[] = $importe !== null
                ? 'Importe reembolsado: $'.number_format($importe, 2, ',', '.').'.'
                : 'Importe reembolsado: no informado por Mercado Libre.'; // FR-004a
        }

        $partes[] = 'Detectado el '.$fechaDeteccion->format(self::FORMATO_FECHA).'.';

        return implode(' ', $partes);
    }

    private function extraerFechaDeteccion(?string $motivoDetalle): ?Carbon
    {
        if (! $motivoDetalle || ! preg_match('/Detectado el (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\./', $motivoDetalle, $m)) {
            return null;
        }

        try {
            return Carbon::createFromFormat(self::FORMATO_FECHA, $m[1]);
        } catch (\Throwable) {
            return null;
        }
    }
}
