<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\MercadoLibre\ReevaluadorOrdenes;
use Illuminate\Support\Facades\DB;

/**
 * Reevalúa las órdenes ML afectadas por una vinculación creada, editada o
 * eliminada después de haberse sincronizado (spec 041, FR-001): mismo patrón
 * que `PrecioProductoObserver` (`DB::afterCommit`, para no reevaluar dentro
 * de una transacción que todavía puede revertirse).
 */
class MercadoLibrePublicacionProductoObserver
{
    public function saved(MercadoLibrePublicacionProducto $publicacion): void
    {
        DB::afterCommit(function () use ($publicacion) {
            app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorPublicacion($publicacion->ml_item_id);
        });
    }

    public function deleted(MercadoLibrePublicacionProducto $publicacion): void
    {
        DB::afterCommit(function () use ($publicacion) {
            app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorPublicacion($publicacion->ml_item_id);
        });
    }
}
