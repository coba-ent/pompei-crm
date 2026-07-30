<?php

namespace App\Console\Commands;

use App\Models\Integraciones\TiendanubeConfiguracion;
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
        $configuracion = TiendanubeConfiguracion::actual();

        if (! $this->option('forzar') && ! $this->correspondeEjecutar($configuracion)) {
            $this->info('Todavía no corresponde sincronizar según la frecuencia configurada.');

            return self::SUCCESS;
        }

        $resultado = $sincronizador->ejecutar();

        if (! $resultado['ok']) {
            $this->error($resultado['mensaje']);

            return ($resultado['tipo'] ?? null) === 'error' ? 2 : 1;
        }

        $this->info($resultado['mensaje']);

        return self::SUCCESS;
    }

    private function correspondeEjecutar(TiendanubeConfiguracion $configuracion): bool
    {
        if (! $configuracion->stock_ultima_sync_en) {
            return true;
        }

        return $configuracion->stock_ultima_sync_en->diffInMinutes(now()) >= $configuracion->frecuencia_sync_minutos;
    }
}
