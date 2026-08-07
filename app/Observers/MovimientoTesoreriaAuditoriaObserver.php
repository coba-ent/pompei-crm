<?php

namespace App\Observers;

use App\Models\MovimientoTesoreria;
use App\Services\AuditoriaService;

/**
 * Genera los eventos de auditoría de MovimientoTesoreria (spec 054). Cubre también la actualización
 * in-place que dispara "Editar cobranza" (spec 053 FR-005): mismo movimiento con monto/cuenta/fecha
 * actualizados genera `modifico` igual que cualquier otro `updated`, sin tratamiento especial.
 */
class MovimientoTesoreriaAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['monto', 'fecha', 'cuenta_tesoreria_id', 'detalle', 'observacion'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(MovimientoTesoreria $movimiento): void
    {
        $this->auditoria->registrarEvento('creo', 'movimiento_tesoreria', $movimiento, $this->detalle($movimiento), (float) $movimiento->monto);
    }

    public function updated(MovimientoTesoreria $movimiento): void
    {
        if (! $movimiento->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'movimiento_tesoreria', $movimiento, $this->detalle($movimiento), (float) $movimiento->monto);
    }

    public function deleted(MovimientoTesoreria $movimiento): void
    {
        $accion = $movimiento->isForceDeleting() ? 'elimino' : 'anulo';
        $this->auditoria->registrarEvento($accion, 'movimiento_tesoreria', $movimiento, $this->detalle($movimiento), (float) $movimiento->monto);
    }

    private function detalle(MovimientoTesoreria $movimiento): string
    {
        $cuenta = optional($movimiento->cuenta)->nombre ?? 'Cuenta';
        $concepto = $movimiento->detalle ?: $movimiento->tipo;

        return "Movimiento — {$cuenta} ({$concepto})";
    }
}
