<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\PrecioProducto;
use App\Services\MercadoLibre\SincronizadorPrecios;
use Illuminate\Support\Facades\DB;

/**
 * Detecta cambios de precio elegibles para empujar hacia Mercado Libre (spec
 * 016, research.md R1-R4): único punto por el que pasa cualquier escritura
 * sobre `precios_producto` (modal de Producto, importación masiva), sin
 * importar el camino que la originó (FR-005). Dispara el envío después del
 * COMMIT de la transacción del llamador (research.md R2) — nunca dentro.
 */
class PrecioProductoObserver
{
    public function saved(PrecioProducto $precio): void
    {
        $listaConfigurada = MercadoLibreConfiguracion::actual()->lista_precio_id;

        if (! $listaConfigurada || (int) $precio->lista_precio_id !== (int) $listaConfigurada) {
            return;
        }

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $precio->producto_id)->first();

        if (! $vinculo) {
            return;
        }

        DB::afterCommit(function () use ($vinculo, $precio) {
            app(SincronizadorPrecios::class)->enviarUno($vinculo, (float) $precio->precio);
        });
    }
}
