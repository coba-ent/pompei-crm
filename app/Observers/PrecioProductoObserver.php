<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\PrecioProducto;
use App\Services\MercadoLibre\SincronizadorPrecios as SincronizadorPreciosMercadoLibre;
use App\Services\Tiendanube\SincronizadorPrecios as SincronizadorPreciosTiendanube;
use Illuminate\Support\Facades\DB;

/**
 * Detecta cambios de precio elegibles para empujar hacia Mercado Libre (spec
 * 016, research.md R1-R4) y hacia Tiendanube (spec 018 ampliación, research.md
 * R8): único punto por el que pasa cualquier escritura sobre `precios_producto`
 * (modal de Producto, importación masiva), sin importar el camino que la
 * originó (FR-005/FR-025). Dispara el envío después del COMMIT de la
 * transacción del llamador (research.md R2) — nunca dentro. Ambas ramas son
 * completamente independientes entre sí (spec 018 plan.md §7).
 */
class PrecioProductoObserver
{
    public function saved(PrecioProducto $precio): void
    {
        $this->ramaMercadoLibre($precio);
        $this->ramaTiendanube($precio);
    }

    private function ramaMercadoLibre(PrecioProducto $precio): void
    {
        $listaConfigurada = MercadoLibreConfiguracion::actual()->lista_precio_id;

        if (! $listaConfigurada || (int) $precio->lista_precio_id !== (int) $listaConfigurada) {
            return;
        }

        $vinculos = MercadoLibrePublicacionProducto::where('producto_id', $precio->producto_id)->get();

        foreach ($vinculos as $vinculo) {
            DB::afterCommit(function () use ($vinculo, $precio) {
                app(SincronizadorPreciosMercadoLibre::class)->enviarUno($vinculo, (float) $precio->precio);
            });
        }
    }

    /** Rama Tiendanube (spec 018 ampliación, FR-024/FR-026/FR-027). */
    private function ramaTiendanube(PrecioProducto $precio): void
    {
        $listaConfigurada = TiendanubeConexionRest::actual()->lista_precio_id;

        if (! $listaConfigurada || (int) $precio->lista_precio_id !== (int) $listaConfigurada) {
            return;
        }

        $vinculos = TiendanubeVarianteProducto::where('producto_id', $precio->producto_id)->get();

        foreach ($vinculos as $vinculo) {
            DB::afterCommit(function () use ($vinculo, $precio) {
                app(SincronizadorPreciosTiendanube::class)->enviarUno($vinculo, (float) $precio->precio);
            });
        }
    }
}
