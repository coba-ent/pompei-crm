<?php

namespace App\Observers;

use App\Models\Compra;
use App\Services\AuditoriaService;

/** Genera los eventos de auditoría de Compra (spec 054). */
class CompraAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['proveedor_id', 'total', 'subtotal_con_descuento', 'nro_comprobante', 'fecha_emision'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(Compra $compra): void
    {
        $this->auditoria->registrarEvento('creo', 'compra', $compra, $this->detalle($compra), (float) $compra->total);
    }

    public function updated(Compra $compra): void
    {
        if (! $compra->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'compra', $compra, $this->detalle($compra), (float) $compra->total);
    }

    public function deleted(Compra $compra): void
    {
        $accion = $compra->isForceDeleting() ? 'elimino' : 'anulo';
        $this->auditoria->registrarEvento($accion, 'compra', $compra, $this->detalle($compra), (float) $compra->total);
    }

    private function detalle(Compra $compra): string
    {
        $proveedor = optional($compra->proveedor)->nombre ?? 'Proveedor';

        return "Compra #{$compra->nro_comprobante} — {$proveedor}";
    }
}
