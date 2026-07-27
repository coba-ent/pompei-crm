<?php

namespace App\Observers;

use App\Models\Compra;
use App\Services\Egresos\Pagos;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Al soft-delete de una Compra, revierte sus pagos y los movimientos de
 * tesorería asociados — sin saldo fantasma (SC-004, research.md §5).
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
            });
        }
    }
}
