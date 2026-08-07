<?php

namespace App\Observers;

use App\Models\Venta;
use App\Services\AuditoriaService;

/** Genera los eventos de auditoría de Venta (spec 054) — no confundir con VentaObserver (efectos de negocio). */
class VentaAuditoriaObserver
{
    /** Campos de negocio cuyo cambio representa una edición real (no recálculos internos, research D7). */
    private const CAMPOS_RELEVANTES = ['cliente_id', 'total', 'subtotal_con_descuento', 'nro_comprobante', 'fecha_emision'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(Venta $venta): void
    {
        $this->auditoria->registrarEvento('creo', 'venta', $venta, $this->detalle($venta), (float) $venta->total, $venta->origen === 'mercadolibre' || $venta->origen === 'tiendanube' ? $venta->origen : null);
    }

    public function updated(Venta $venta): void
    {
        if (! $venta->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'venta', $venta, $this->detalle($venta), (float) $venta->total);
    }

    public function deleted(Venta $venta): void
    {
        $accion = $venta->isForceDeleting() ? 'elimino' : 'anulo';
        $this->auditoria->registrarEvento($accion, 'venta', $venta, $this->detalle($venta), (float) $venta->total);
    }

    private function detalle(Venta $venta): string
    {
        $cliente = optional($venta->cliente)->nombre ?? 'Consumidor Final';

        return "Venta #{$venta->nro_comprobante} — {$cliente}";
    }
}
