<?php

namespace App\Observers;

use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Services\Tiendanube\ReevaluadorOrdenes;
use Illuminate\Support\Facades\DB;

/**
 * Reevalúa las órdenes TiendaNube afectadas por una vinculación creada,
 * editada o eliminada después de haberse sincronizado (spec 041, FR-002):
 * análogo a `MercadoLibrePublicacionProductoObserver`.
 */
class TiendanubeVarianteProductoObserver
{
    public function saved(TiendanubeVarianteProducto $variante): void
    {
        DB::afterCommit(function () use ($variante) {
            app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorVariante((string) $variante->variant_id);
        });
    }

    public function deleted(TiendanubeVarianteProducto $variante): void
    {
        DB::afterCommit(function () use ($variante) {
            app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorVariante((string) $variante->variant_id);
        });
    }
}
