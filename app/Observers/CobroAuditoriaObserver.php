<?php

namespace App\Observers;

use App\Models\Cobro;
use App\Services\AuditoriaService;

/**
 * Genera los eventos de auditoría de Cobro (spec 054). El `updated` filtrado incluye monto, fecha,
 * cuenta_tesoreria_id y nota — exactamente los campos editables desde "Editar cobranza" (spec 053).
 */
class CobroAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['monto', 'fecha', 'cuenta_tesoreria_id', 'nota'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(Cobro $cobro): void
    {
        $this->auditoria->registrarEvento('creo', 'cobro', $cobro, $this->detalle($cobro), (float) $cobro->monto);
    }

    public function updated(Cobro $cobro): void
    {
        if (! $cobro->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'cobro', $cobro, $this->detalle($cobro), (float) $cobro->monto);
    }

    public function deleted(Cobro $cobro): void
    {
        $accion = $cobro->isForceDeleting() ? 'elimino' : 'anulo';
        $this->auditoria->registrarEvento($accion, 'cobro', $cobro, $this->detalle($cobro), (float) $cobro->monto);
    }

    private function detalle(Cobro $cobro): string
    {
        $cliente = optional(optional($cobro->venta)->cliente)->nombre ?? 'Consumidor Final';
        $cuenta = optional($cobro->cuentaTesoreria)->nombre ?? 'Cuenta';

        return "Cobro — {$cliente} ({$cuenta})";
    }
}
