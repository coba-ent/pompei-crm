<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\TiendanubeOrden;
use Illuminate\Console\Command;

/**
 * Backfill de un solo uso (spec 038, FR-010/R4): completa `ventas.ml_order_id`/
 * `tn_order_id` para las Ventas mercadolibre/tiendanube ya existentes, a partir
 * de la orden vigente que las referencia. Las Ventas cuya orden de origen ya no
 * existe (borrada antes de esta feature) quedan sin tocar.
 */
class BackfillReferenciaPedidoVentas extends Command
{
    protected $signature = 'ventas:backfill-referencia-pedido';

    protected $description = 'Completa ventas.ml_order_id/tn_order_id a partir de las órdenes ML/Tiendanube vigentes';

    public function handle(): int
    {
        $ml = 0;

        MercadoLibreOrden::whereNotNull('venta_id')->each(function (MercadoLibreOrden $orden) use (&$ml) {
            $actualizadas = $orden->venta()
                ->withTrashed()
                ->whereNull('ml_order_id')
                ->update(['ml_order_id' => $orden->ml_order_id]);

            $ml += $actualizadas;
        });

        $tn = 0;

        TiendanubeOrden::whereNotNull('venta_id')->each(function (TiendanubeOrden $orden) use (&$tn) {
            $actualizadas = $orden->venta()
                ->withTrashed()
                ->whereNull('tn_order_id')
                ->update(['tn_order_id' => $orden->tn_order_id]);

            $tn += $actualizadas;
        });

        $this->info("Ventas actualizadas: {$ml} (Mercado Libre), {$tn} (Tiendanube).");

        return self::SUCCESS;
    }
}
