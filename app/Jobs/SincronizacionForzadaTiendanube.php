<?php

namespace App\Jobs;

use App\Models\Integraciones\TiendanubeConexionRest;
use App\Services\Tiendanube\SincronizadorPrecios;
use App\Services\Tiendanube\SincronizadorStock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Sincronización forzada" de Tiendanube corrida en cola (spec 035), mismo
 * motivo y mismo cuerpo que SincronizacionForzadaMercadoLibre — ver esa
 * clase para el detalle del bug de timeout que la originó.
 */
class SincronizacionForzadaTiendanube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESTADO_CACHE_KEY = 'tn:sincronizacion_forzada:estado';

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(SincronizadorStock $sincronizadorStock, SincronizadorPrecios $sincronizadorPrecios): void
    {
        try {
            $resultadoStock = $sincronizadorStock->sincronizarTodos();

            if (! $resultadoStock['ok']) {
                Cache::put(self::ESTADO_CACHE_KEY, [
                    'estado' => 'error',
                    'mensaje' => $resultadoStock['mensaje'],
                    'terminado_en' => now()->toIso8601String(),
                ], now()->addMinutes(15));

                return;
            }

            $conexion = TiendanubeConexionRest::actual();
            $resultadoPrecio = null;

            if ($conexion->lista_precio_id) {
                $resultadoPrecio = $sincronizadorPrecios->sincronizarListaCompleta($conexion->lista_precio_id);
            }

            $precioBloqueado = $resultadoPrecio && ! ($resultadoPrecio['ok'] ?? false);

            $mensaje = match (true) {
                $precioBloqueado => "{$resultadoStock['mensaje']} (stock) — precio no sincronizado: {$resultadoPrecio['mensaje']}",
                (bool) $resultadoPrecio => "{$resultadoStock['mensaje']} (stock) — {$resultadoPrecio['mensaje']} (precio)",
                default => "{$resultadoStock['mensaje']} (stock) — sin lista de precios configurada, precio no sincronizado.",
            };

            Cache::put(self::ESTADO_CACHE_KEY, [
                'estado' => 'ok',
                'mensaje' => $mensaje,
                'terminado_en' => now()->toIso8601String(),
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            Cache::put(self::ESTADO_CACHE_KEY, [
                'estado' => 'error',
                'mensaje' => 'No se pudo completar la sincronización forzada: '.$e->getMessage(),
                'terminado_en' => now()->toIso8601String(),
            ], now()->addMinutes(15));

            throw $e;
        }
    }
}
