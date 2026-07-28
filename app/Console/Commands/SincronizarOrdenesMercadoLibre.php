<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Illuminate\Console\Command;

/**
 * Tarea programada de sincronización (contracts §4, research.md §R5): se
 * registra con evaluación por minuto y decide en cada disparo si corresponde
 * ejecutar, comparando el tiempo transcurrido desde `ultima_sync_en` contra
 * `frecuencia_sync_minutos`. `--forzar` ignora esa comparación pero no los
 * cortes de FR-017/FR-018 (los sigue aplicando SincronizadorOrdenes).
 */
class SincronizarOrdenesMercadoLibre extends Command
{
    protected $signature = 'mercadolibre:sincronizar-ordenes {--forzar : Ignora la frecuencia configurada, pero no los bloqueos}';

    protected $description = 'Sincroniza las órdenes de venta de Mercado Libre según la frecuencia configurada';

    public function handle(SincronizadorOrdenes $sincronizador): int
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
        if (! $configuracion->ultima_sync_en) {
            return true;
        }

        return $configuracion->ultima_sync_en->diffInMinutes(now()) >= $configuracion->frecuencia_sync_minutos;
    }
}
