<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\PrecioProducto;
use App\Services\AuditoriaService;
use App\Services\MercadoLibre\SincronizadorPrecios as SincronizadorPreciosMercadoLibre;
use App\Services\Tiendanube\SincronizadorPrecios as SincronizadorPreciosTiendanube;
use App\Support\OrigenCambioPrecio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $this->ramaAuditoria($precio);
        $this->ramaMercadoLibre($precio);
        $this->ramaTiendanube($precio);
    }

    /** Borrado de un precio: se audita con el valor que tenía (spec 074, T009). */
    public function deleted(PrecioProducto $precio): void
    {
        $this->registrarAuditoria($precio, 'elimino', (float) $precio->precio, null);
    }

    /**
     * Rama de auditoría (spec 074, FR-006 a FR-010) — independiente de las de Mercado Libre
     * y Tiendanube: un fallo acá no debe impedir que aquéllas corran, y viceversa.
     *
     * Sobre `getOriginal()` dentro de `saved()` (no lo "corrijas"): Laravel dispara el evento
     * `saved` desde `Model::finishSave()` **antes** de llamar a `syncOriginal()`, así que acá
     * `getOriginal('precio')` todavía devuelve el valor previo al guardado y `wasChanged()`
     * refleja el cambio recién hecho. Es correcto y deliberado. Leerlo con una consulta extra
     * agregaría una query por evento sin necesidad; moverlo a `updated()`/`saving()` cambiaría
     * la semántica.
     */
    private function ramaAuditoria(PrecioProducto $precio): void
    {
        $nuevo = (float) $precio->precio;

        if ($precio->wasRecentlyCreated) {
            $this->registrarAuditoria($precio, 'creo', null, $nuevo);

            return;
        }

        if (! $precio->wasChanged('precio')) {
            return;
        }

        $anterior = (float) $precio->getOriginal('precio');

        // La comparación va sobre el valor normalizado a 2 decimales (`precios_producto.precio`
        // es decimal(14,2)): 100 y 100.00 NO son un cambio (FR-010). Sin esto, reimportar una
        // planilla sin modificaciones generaría miles de eventos espurios.
        if (round($anterior, 2) === round($nuevo, 2)) {
            return;
        }

        $this->registrarAuditoria($precio, 'modifico', $anterior, $nuevo);
    }

    /** Arma y despacha el evento de auditoría de un cambio de precio (spec 074, T010). */
    private function registrarAuditoria(PrecioProducto $precio, string $tipoAccion, ?float $anterior, ?float $nuevo): void
    {
        try {
            $producto = $precio->producto()->first();

            if (! $producto) {
                return;
            }

            app(AuditoriaService::class)->registrarEvento(
                $tipoAccion,
                'precio_producto',
                $producto,
                $this->detalle($producto->nombre, $precio, $anterior, $nuevo),
                $nuevo,
            );
        } catch (Throwable $e) {
            // FR-012: la auditoría documenta, no gatea. Un fallo acá nunca puede abortar el
            // guardado del precio ni impedir las ramas de integración.
            Log::error('PrecioProductoObserver: fallo en la rama de auditoría', [
                'precio_producto_id' => $precio->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"`, con `sin precio` cuando falta
     * un extremo. `logs_auditoria.detalle` es varchar(255): el recorte sacrifica el **nombre
     * del producto**, nunca los importes ni el rótulo de origen, que son lo que hace útil al
     * registro.
     */
    private function detalle(string $nombreProducto, PrecioProducto $precio, ?float $anterior, ?float $nuevo): string
    {
        $lista = $precio->listaPrecio()->first()?->nombre ?? "Lista #{$precio->lista_precio_id}";
        $importe = fn (?float $v) => $v === null ? 'sin precio' : number_format($v, 2, ',', '.');

        $cola = sprintf(
            ' — %s: %s → %s (%s)',
            $lista,
            $importe($anterior),
            $importe($nuevo),
            OrigenCambioPrecio::rotulo(),
        );

        $espacioParaNombre = 255 - mb_strlen($cola);

        return mb_substr($nombreProducto, 0, max(0, $espacioParaNombre)).$cola;
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
