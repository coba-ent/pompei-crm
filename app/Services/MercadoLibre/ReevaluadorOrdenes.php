<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOrden;

/**
 * Reevalúa órdenes ML fuera del flujo de sincronización (spec 041): mismo
 * bloque de lógica que `SincronizadorOrdenes::procesarOrden()`/
 * `intentarCreacionAutomatica()`, extraído para que lo consuman el Observer
 * de vinculación (evento-driven) y el `datatable()` de pendientes (on-view)
 * sin duplicarlo. Contrato: contracts/reevaluador-ordenes.md.
 */
class ReevaluadorOrdenes
{
    public function __construct(
        private readonly EvaluadorConvertibilidad $evaluador,
        private readonly ConversorOrdenAVenta $conversor,
        private readonly ResolutorCliente $resolutorCliente,
    ) {
    }

    /** No-op si la orden ya tiene `venta_id` (FR-005) — defensa en profundidad además del filtro en la query. */
    public function reevaluarUna(MercadoLibreOrden $orden, ?int $usuarioId = null): void
    {
        if ($orden->venta_id) {
            return;
        }

        ['cliente' => $clienteExistente, 'ambiguo' => $clienteAmbiguo] =
            $this->resolutorCliente->buscarExistente($orden);

        [$estado, $motivo, $detalle] = $this->evaluador->evaluar($orden, $clienteAmbiguo);

        $orden->update([
            'estado_conversion' => $estado->value,
            'motivo' => $motivo?->value,
            'motivo_detalle' => $detalle,
            'cliente_nuevo' => ! $clienteExistente && ! $clienteAmbiguo,
        ]);

        if ($estado === EstadoConversion::Lista && MercadoLibreConfiguracion::actual()->creacion_automatica) {
            $this->intentarCreacionAutomatica($orden, $usuarioId);
        }
    }

    /** Órdenes afectadas por la vinculación de un `ml_item_id`: no convertidas, en requiere_atencion|lista (FR-010). */
    public function reevaluarAfectadasPorPublicacion(string $mlItemId, ?int $usuarioId = null): int
    {
        $ordenes = MercadoLibreOrden::whereNull('venta_id')
            ->whereIn('estado_conversion', [EstadoConversion::RequiereAtencion->value, EstadoConversion::Lista->value])
            ->whereHas('items', fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->get();

        foreach ($ordenes as $orden) {
            $this->reevaluarUna($orden, $usuarioId);
        }

        return $ordenes->count();
    }

    /** Barrida on-view: todas las órdenes `requiere_atencion` no convertidas del canal (FR-006/FR-007). */
    public function reevaluarPendientesDelCanal(?int $usuarioId = null): int
    {
        $ordenes = MercadoLibreOrden::whereNull('venta_id')
            ->where('estado_conversion', EstadoConversion::RequiereAtencion->value)
            ->get();

        foreach ($ordenes as $orden) {
            $this->reevaluarUna($orden, $usuarioId);
        }

        return $ordenes->count();
    }

    /** FR-004: ante un fallo inesperado, la orden queda con el motivo y el error registrado, sin Venta parcial. */
    private function intentarCreacionAutomatica(MercadoLibreOrden $orden, ?int $usuarioId): void
    {
        try {
            $this->conversor->convertir($orden, $usuarioId, automatica: true);
        } catch (\Throwable $e) {
            $orden->update([
                'estado_conversion' => EstadoConversion::RequiereAtencion->value,
                'motivo' => MotivoRequiereAtencion::ErrorConversion->value,
                'motivo_detalle' => 'Falla inesperada durante la creación automática: '.$e->getMessage(),
            ]);
        }
    }
}
