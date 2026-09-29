<?php

namespace App\Observers;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\MovimientoStock;

/**
 * Detecta cambios de stock elegibles para empujar hacia Mercado Libre (spec 013,
 * research.md R1) y hacia Tiendanube (spec 018, research.md R1): único punto por
 * el que pasa cualquier movimiento de stock del CRM (Ventas, ajustes,
 * transferencias), sin importar el módulo que lo originó. Marca el vínculo como
 * pendiente; no envía nada — eso lo hacen los `SincronizadorStock` de cada
 * integración. Ambas ramas son independientes entre sí (plan.md §"Enfoque
 * técnico" punto 1 de la spec 018).
 */
class MovimientoStockObserver
{
    public function created(MovimientoStock $movimiento): void
    {
        $this->ramaMercadoLibre($movimiento);
        $this->ramaTiendanube($movimiento);
    }

    private function ramaMercadoLibre(MovimientoStock $movimiento): void
    {
        $depositoMl = MercadoLibreConfiguracion::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoMl->id) {
            return;
        }

        // Se marcan TODAS las publicaciones del producto, **incluida la que vendió** (spec 112).
        //
        // Antes se la excluía, porque Mercado Libre ya descuenta el stock de la publicación por la
        // que se vendió y volver a empujárselo parecía redundante. Pero el stock que el CRM empuja
        // puede estar viejo: las órdenes se importan cada 5 minutos, así que entre una pasada y la
        // siguiente el CRM todavía no sabe de ventas que allá ya ocurrieron. Si en esa ventana el
        // cron le manda un número desactualizado y después la publicación queda excluida, **nadie
        // vuelve a corregirla**.
        //
        // Pasó el 28/09/2026 con MLA1808325052: el CRM le empujó 22 a las 18:19 (aún no había
        // importado dos ventas), ML restó 1 por su propia venta y quedó en 21 mientras el CRM tenía
        // 20. Sin error, sin pendiente, sin marca: sólo lo detectó el chequeo de rutina.
        //
        // Empujar de más es inofensivo: SincronizadorStock::procesarVinculos() lee el stock al
        // momento del envío, no al marcar, así que un PUT redundante siempre lleva el valor real.
        // Es el mismo criterio que la spec 013 ya fijó en FR-003 para los movimientos que se
        // cancelan entre sí: enviar de más es más seguro que arriesgar una divergencia silenciosa.
        //
        // No hay riesgo de bucle —el motivo por el que la exclusión nació en la spec 013, FR-002—:
        // publicar stock no crea ningún MovimientoStock, sólo escribe columnas de control del
        // vínculo, así que este observer no se vuelve a disparar.
        MercadoLibrePublicacionProducto::where('producto_id', $movimiento->producto_id)
            ->update(['stock_pendiente' => true]);
    }

    /** Rama Tiendanube (spec 018, FR-001/FR-002/FR-005): mismo esqueleto que la de Mercado Libre. */
    private function ramaTiendanube(MovimientoStock $movimiento): void
    {
        $depositoTn = TiendanubeConexionRest::actual()->depositoEfectivo();

        if ((int) $movimiento->deposito_id !== $depositoTn->id) {
            return;
        }

        // Mismo criterio que la rama de Mercado Libre (spec 112): se marcan todas las variantes,
        // incluida la que vendió. Ver el comentario extenso allá arriba.
        TiendanubeVarianteProducto::where('producto_id', $movimiento->producto_id)
            ->update(['stock_pendiente' => true]);
    }

}
