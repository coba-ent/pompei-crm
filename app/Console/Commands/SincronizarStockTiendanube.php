<?php

namespace App\Console\Commands;

use App\Models\Integraciones\TiendanubeConexionRest;
use App\Services\Tiendanube\SincronizadorStock;
use Illuminate\Console\Command;

/**
 * Tarea programada de sincronización de stock hacia Tiendanube (spec 018,
 * contracts §4): mismo patrón que SincronizarOrdenesTiendanube, reutilizando
 * `frecuencia_sync_minutos` contra `stock_ultima_sync_en`. `--forzar` ignora
 * esa comparación pero no los cortes de FR-009/FR-010 (los sigue aplicando
 * SincronizadorStock).
 */
class SincronizarStockTiendanube extends Command
{
    protected $signature = 'tiendanube:sincronizar-stock {--forzar : Ignora la frecuencia configurada, pero no los bloqueos}';

    protected $description = 'Empuja hacia Tiendanube el stock de las variantes vinculadas con cambios pendientes';

    public function handle(SincronizadorStock $sincronizador): int
    {
        $configuracion = TiendanubeConexionRest::actual();

        if (! $this->option('forzar') && ! $this->correspondeEjecutar($configuracion)) {
            $this->info('Todavía no corresponde sincronizar según la frecuencia configurada.');

            return self::SUCCESS;
        }

        $resultado = $sincronizador->ejecutar();

        // Sólo un fallo REAL devuelve un exit distinto de cero. `bloqueada` (modo sólo lectura,
        // o la función desactivada en Funciones Avanzadas) y `salteada` (ya hay otra corrida en
        // curso) son no-ops deliberados: el comando hizo exactamente lo que correspondía, que era
        // no hacer nada. Devolverlos como fallo hacía que el scheduler los registrara como
        // `production.ERROR` en CADA corrida del cron — con frecuencia por minuto eso llenó 127 MB
        // de `laravel.log` en el VPS (16.649 entradas en 20 días), enterrando cualquier error de
        // verdad entre miles de falsas alarmas. El motivo se sigue informando por salida estándar.
        if (($resultado['tipo'] ?? null) === 'error') {
            $this->error($resultado['mensaje']);

            return 2;
        }

        if (! $resultado['ok']) {
            $this->info($resultado['mensaje']);

            return self::SUCCESS;
        }

        $this->info($resultado['mensaje']);

        return self::SUCCESS;
    }

    private function correspondeEjecutar(TiendanubeConexionRest $configuracion): bool
    {
        if (! $configuracion->stock_ultima_sync_en) {
            return true;
        }

        return $configuracion->stock_ultima_sync_en->diffInMinutes(now()) >= $configuracion->frecuencia_sync_minutos;
    }
}
