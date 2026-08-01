<?php

namespace App\Observers;

use App\Models\Compra;
use App\Services\Egresos\Pagos;
use App\Services\Egresos\StockDeCompra;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Al soft-delete de una Compra, revierte sus pagos y los movimientos de
 * tesorería asociados — sin saldo fantasma (SC-004, research.md §5) — y
 * reintegra el stock sumado (FR-004, spec 030).
 */
class CompraObserver
{
    public function deleting(Compra $compra): void
    {
        if (! $compra->isForceDeleting()) {
            DB::transaction(function () use ($compra) {
                $pagos = App::make(Pagos::class);
                foreach ($compra->pagos as $pago) {
                    $pagos->anularPago($pago);
                }

                App::make(StockDeCompra::class)->reintegrarPorEliminacion($compra->load('items.producto'));
            });
        }
    }
}
