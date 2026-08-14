<?php

namespace App\Jobs;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorPrecios;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\MercadoLibre\SincronizadorStockFull;
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

    public function handle(
        SincronizadorStock $sincronizadorStock,
        SincronizadorPrecios $sincronizadorPrecios,
        SincronizadorStockFull $sincronizadorStockFull,
    ): void {
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

            // spec 065/T028: reflejo ML → CRM encadenado después del push. Su resultado se
            // suma al mensaje pero nunca marca la corrida como fallida (FR-014): que falte
            // configurar el depósito Full no invalida un push de stock que salió bien.
            $resultadoFull = $sincronizadorStockFull->ejecutar();

            $configuracion = MercadoLibreConfiguracion::actual();
            $resultadoPrecio = null;

            if ($configuracion->lista_precio_id) {
                $resultadoPrecio = $sincronizadorPrecios->sincronizarListaCompleta($configuracion->lista_precio_id);
            }

            $precioBloqueado = $resultadoPrecio && ! ($resultadoPrecio['ok'] ?? false);

            // SC-007: sin publicaciones Full vinculadas el tramo de Full no aporta nada al
            // mensaje, así que queda idéntico al de antes de la spec 065.
            $tramoFull = $this->tramoFull($resultadoFull);

            $mensaje = match (true) {
                $precioBloqueado => "{$resultadoStock['mensaje']} (stock){$tramoFull} — precio no sincronizado: {$resultadoPrecio['mensaje']}",
                (bool) $resultadoPrecio => "{$resultadoStock['mensaje']} (stock){$tramoFull} — {$resultadoPrecio['mensaje']} (precio)",
                default => "{$resultadoStock['mensaje']} (stock){$tramoFull} — sin lista de precios configurada, precio no sincronizado.",
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

    /**
     * Tramo de Full del mensaje de estado (contracts §3). Devuelve cadena vacía cuando no
     * hay nada que contar —ni publicaciones Full ni un aviso de configuración faltante—
     * para no alterar el mensaje de una cuenta sin Full (SC-007).
     */
    private function tramoFull(array $resultadoFull): string
    {
        if (! $resultadoFull['ok']) {
            return " — {$resultadoFull['mensaje']}";
        }

        $huboActividad = $resultadoFull['actualizados'] > 0
            || $resultadoFull['sin_cambios'] > 0
            || $resultadoFull['con_error'] > 0
            || $resultadoFull['conflictos'] > 0;

        return $huboActividad ? " — {$resultadoFull['mensaje']}" : '';
    }
}
