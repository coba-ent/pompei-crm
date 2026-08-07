<?php

namespace App\Observers;

use App\Models\Presupuesto;
use App\Services\AuditoriaService;

/** Genera los eventos de auditoría de Presupuesto (spec 054). */
class PresupuestoAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['cliente_id', 'total', 'subtotal_con_descuento', 'estado', 'fecha_emision'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(Presupuesto $presupuesto): void
    {
        $this->auditoria->registrarEvento('creo', 'presupuesto', $presupuesto, $this->detalle($presupuesto), (float) $presupuesto->total);
    }

    public function updated(Presupuesto $presupuesto): void
    {
        if (! $presupuesto->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $accion = $presupuesto->wasChanged('estado') && $presupuesto->estado === 'anulado' ? 'anulo' : 'modifico';
        $this->auditoria->registrarEvento($accion, 'presupuesto', $presupuesto, $this->detalle($presupuesto), (float) $presupuesto->total);
    }

    public function deleted(Presupuesto $presupuesto): void
    {
        $this->auditoria->registrarEvento('elimino', 'presupuesto', $presupuesto, $this->detalle($presupuesto), (float) $presupuesto->total);
    }

    private function detalle(Presupuesto $presupuesto): string
    {
        $cliente = optional($presupuesto->cliente)->nombre ?? 'Consumidor Final';

        return "Presupuesto #{$presupuesto->nro_presupuesto} — {$cliente}";
    }
}
