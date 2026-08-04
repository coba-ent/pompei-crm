<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConversion;
use App\Enums\Tiendanube\MotivoRequiereAtencion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;

/**
 * Reevalúa órdenes TiendaNube fuera del flujo de sincronización (spec 041):
 * mismo bloque de lógica que `SincronizadorOrdenes::procesarOrden()`/
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
    public function reevaluarUna(TiendanubeOrden $orden, ?int $usuarioId = null): void
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
        ]);

        if ($estado === EstadoConversion::Lista && TiendanubeConexionRest::actual()->creacion_automatica) {
            $this->intentarCreacionAutomatica($orden, $usuarioId);
        }
    }

    /** Órdenes afectadas por la vinculación de un `variant_id`: no convertidas, en requiere_atencion|lista (FR-010). */
    public function reevaluarAfectadasPorVariante(string $variantId, ?int $usuarioId = null): int
    {
        $ordenes = TiendanubeOrden::whereNull('venta_id')
            ->whereIn('estado_conversion', [EstadoConversion::RequiereAtencion->value, EstadoConversion::Lista->value])
            ->whereHas('items', fn ($q) => $q->where('variant_id', $variantId))
            ->get();

        foreach ($ordenes as $orden) {
            $this->reevaluarUna($orden, $usuarioId);
        }

        return $ordenes->count();
    }

    /** Barrida on-view: todas las órdenes `requiere_atencion` no convertidas del canal (FR-006/FR-007). */
    public function reevaluarPendientesDelCanal(?int $usuarioId = null): int
    {
        $ordenes = TiendanubeOrden::whereNull('venta_id')
            ->where('estado_conversion', EstadoConversion::RequiereAtencion->value)
            ->get();

        foreach ($ordenes as $orden) {
            $this->reevaluarUna($orden, $usuarioId);
        }

        return $ordenes->count();
    }

    /** FR-004: ante un fallo inesperado, la orden queda con el motivo y el error registrado, sin Venta parcial. */
    private function intentarCreacionAutomatica(TiendanubeOrden $orden, ?int $usuarioId): void
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
