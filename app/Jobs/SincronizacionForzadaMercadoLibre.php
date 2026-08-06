<?php

namespace App\Jobs;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorPrecios;
use App\Services\MercadoLibre\SincronizadorStock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Sincronización forzada" de Mercado Libre corrida en cola (spec 035),
 * sacada del ciclo request/response del botón: con catálogos grandes (270+
 * vínculos) la corrida síncrona superaba el proxy_read_timeout de Nginx y el
 * botón mostraba "No se pudo ejecutar..." aunque la sincronización seguía
 * corriendo del lado del servidor y terminaba bien igual (detectado
 * 06/08/2026 con captura de un 504 real en el VPS). Mismo cuerpo que tenía
 * MercadoLibreVinculacionController::sincronizacionForzada().
 */
class SincronizacionForzadaMercadoLibre implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESTADO_CACHE_KEY = 'ml:sincronizacion_forzada:estado';

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

            $configuracion = MercadoLibreConfiguracion::actual();
            $resultadoPrecio = null;

            if ($configuracion->lista_precio_id) {
                $resultadoPrecio = $sincronizadorPrecios->sincronizarListaCompleta($configuracion->lista_precio_id);
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
