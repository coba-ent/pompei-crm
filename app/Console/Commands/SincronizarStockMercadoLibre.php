<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorStock;
use Illuminate\Console\Command;

/**
 * Tarea programada de sincronización de stock (spec 013, contracts §4): mismo
 * patrón que SincronizarOrdenesMercadoLibre, reutilizando `frecuencia_sync_minutos`
 * contra `stock_ultima_sync_en`. `--forzar` ignora esa comparación pero no los
 * cortes de FR-009/FR-010 (los sigue aplicando SincronizadorStock).
 */
class SincronizarStockMercadoLibre extends Command
{
    protected $signature = 'mercadolibre:sincronizar-stock {--forzar : Ignora la frecuencia configurada, pero no los bloqueos}';

    protected $description = 'Empuja hacia Mercado Libre el stock de los productos vinculados con cambios pendientes';

    public function handle(SincronizadorStock $sincronizador): int
    {
        $configuracion = MercadoLibreConfiguracion::actual();

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

    private function correspondeEjecutar(MercadoLibreConfiguracion $configuracion): bool
    {
        if (! $configuracion->stock_ultima_sync_en) {
            return true;
        }

        return $configuracion->stock_ultima_sync_en->diffInMinutes(now()) >= $configuracion->frecuencia_sync_minutos;
    }
}
