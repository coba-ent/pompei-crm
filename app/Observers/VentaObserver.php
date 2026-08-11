<?php

namespace App\Observers;

use App\Models\Venta;
use App\Services\Ingresos\Cobranzas;
use App\Services\Ingresos\StockDeVenta;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Al soft-delete de una Venta, revierte sus cobros y los movimientos de
 * tesorería asociados — sin saldo fantasma (SC-005, research.md §4) — y
 * reintegra el stock descontado (FR-046b, spec 012 research.md §R1).
 */
class VentaObserver
{
    public function deleting(Venta $venta): void
    {
        if (! $venta->isForceDeleting()) {
            DB::transaction(function () use ($venta) {
                $cobranzas = App::make(Cobranzas::class);
                foreach ($venta->cobros as $cobro) {
                    $cobranzas->anularCobro($cobro);
                }

                // Las ventas migradas de Contagram (legacy_id) se importaron SIN descontar stock:
                // son historia, el stock que reflejan ya está consolidado en el saldo actual.
                // Reintegrarlo devolvería mercadería que nunca salió e inflaría el stock en
                // silencio. Los cobros sí se revierten: esos movimientos de tesorería existen.
                if ($venta->legacy_id !== null) {
                    return;
                }

                App::make(StockDeVenta::class)->reintegrarPorEliminacion($venta->load('items.producto'));
            });
        }
    }
}
