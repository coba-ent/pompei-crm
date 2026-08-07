<?php

namespace App\Observers;

use App\Models\Gasto;
use App\Services\AuditoriaService;

/** Genera los eventos de auditoría de Gasto (spec 054). */
class GastoAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['monto', 'fecha', 'categoria_id', 'cuenta_tesoreria_id', 'descripcion', 'pendiente'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(Gasto $gasto): void
    {
        $this->auditoria->registrarEvento('creo', 'gasto', $gasto, $this->detalle($gasto), (float) $gasto->monto);
    }

    public function updated(Gasto $gasto): void
    {
        if (! $gasto->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'gasto', $gasto, $this->detalle($gasto), (float) $gasto->monto);
    }

    public function deleted(Gasto $gasto): void
    {
        $accion = $gasto->isForceDeleting() ? 'elimino' : 'anulo';
        $this->auditoria->registrarEvento($accion, 'gasto', $gasto, $this->detalle($gasto), (float) $gasto->monto);
    }

    private function detalle(Gasto $gasto): string
    {
        $categoria = optional($gasto->categoria)->nombre ?? 'Gasto';
        $concepto = $gasto->descripcion ?: $categoria;

        return "Gasto — {$concepto}";
    }
}
