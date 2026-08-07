<?php

namespace App\Observers;

use App\Models\MovimientoStock;
use App\Services\AuditoriaService;

/** Genera los eventos de auditoría de MovimientoStock (spec 054). Sin `total` (nullable) — no aplica. */
class MovimientoStockAuditoriaObserver
{
    private const CAMPOS_RELEVANTES = ['cantidad', 'deposito_id', 'tipo', 'descripcion', 'fecha'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    public function created(MovimientoStock $movimiento): void
    {
        $this->auditoria->registrarEvento('creo', 'movimiento_stock', $movimiento, $this->detalle($movimiento));
    }

    public function updated(MovimientoStock $movimiento): void
    {
        if (! $movimiento->wasChanged(self::CAMPOS_RELEVANTES)) {
            return;
        }

        $this->auditoria->registrarEvento('modifico', 'movimiento_stock', $movimiento, $this->detalle($movimiento));
    }

    public function deleted(MovimientoStock $movimiento): void
    {
        $this->auditoria->registrarEvento('elimino', 'movimiento_stock', $movimiento, $this->detalle($movimiento));
    }

    private function detalle(MovimientoStock $movimiento): string
    {
        $producto = optional($movimiento->producto)->nombre ?? 'Producto';
        $deposito = optional($movimiento->deposito)->nombre ?? 'Depósito';

        return "Movimiento de stock — {$producto} ({$deposito})";
    }
}
