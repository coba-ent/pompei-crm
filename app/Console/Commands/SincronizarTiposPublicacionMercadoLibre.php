<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorTiposPublicacion;
use Illuminate\Console\Command;

/**
 * Revisión manual del tipo de publicación (spec 050, US3). Desde la spec 116 ya no está programada:
 * la dispara `mercadolibre:sincronizar-stock` en cada corrida, frenada por `frecuencia_sync_minutos`.
 * Este comando queda para correrla a mano, con el mismo freno (`--forzar` lo saltea) y la misma
 * detección de cambios hechos en Mercado Libre.
 */
class SincronizarTiposPublicacionMercadoLibre extends Command
{
    protected $signature = 'mercadolibre:sincronizar-tipos-publicacion {--forzar : Ignora la frecuencia configurada}';

    protected $description = 'Refresca el tipo de publicación (Premium/Clásica) de las publicaciones vinculadas de Mercado Libre y publica el precio de los cambios hechos en ML';

    public function handle(SincronizadorTiposPublicacion $sincronizador): int
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        if (! $this->option('forzar') && ! $this->correspondeEjecutar($configuracion)) {
            $this->info('Todavía no corresponde sincronizar según la frecuencia configurada.');

            return self::SUCCESS;
        }

        $resultado = $sincronizador->sincronizarTodos();

        $this->info("{$resultado['actualizados']} publicaciones actualizadas, {$resultado['con_error']} con error. "
            ."Cambios hechos en Mercado Libre: {$resultado['cambios_detectados']} detectados, {$resultado['cambios_procesados']} procesados, "
            ."{$resultado['cambios_postergados']} postergados.");

        return self::SUCCESS;
    }

    private function correspondeEjecutar(MercadoLibreConfiguracion $configuracion): bool
    {
        if (! $configuracion->tipo_publicacion_ultima_sync_en) {
            return true;
        }

        return $configuracion->tipo_publicacion_ultima_sync_en->diffInMinutes(now()) >= $configuracion->frecuencia_sync_minutos;
    }
}
