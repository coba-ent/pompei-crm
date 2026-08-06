<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorTiposPublicacion;
use Illuminate\Console\Command;

/**
 * Tarea programada diaria (spec 050, US3, research.md R3): mismo patrón que
 * SincronizarStockMercadoLibre, pero contra un intervalo fijo de 24hs
 * (`tipo_publicacion_ultima_sync_en`), no configurable — la Clarification ya
 * fijó "diaria" como valor de negocio, independiente de `frecuencia_sync_minutos`
 * (que gobierna la corrida de stock, cada 15 minutos).
 */
class SincronizarTiposPublicacionMercadoLibre extends Command
{
    private const INTERVALO_HORAS = 24;

    protected $signature = 'mercadolibre:sincronizar-tipos-publicacion {--forzar : Ignora el intervalo de 24hs}';

    protected $description = 'Refresca el tipo de publicación (Premium/Clásica) de las publicaciones vinculadas de Mercado Libre';

    public function handle(SincronizadorTiposPublicacion $sincronizador): int
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        if (! $this->option('forzar') && ! $this->correspondeEjecutar($configuracion)) {
            $this->info('Todavía no corresponde sincronizar: no pasaron 24hs desde la última corrida.');

            return self::SUCCESS;
        }

        $resultado = $sincronizador->sincronizarTodos();

        $this->info("{$resultado['actualizados']} publicaciones actualizadas, {$resultado['con_error']} con error.");

        return self::SUCCESS;
    }

    private function correspondeEjecutar(MercadoLibreConfiguracion $configuracion): bool
    {
        if (! $configuracion->tipo_publicacion_ultima_sync_en) {
            return true;
        }

        return $configuracion->tipo_publicacion_ultima_sync_en->diffInHours(now()) >= self::INTERVALO_HORAS;
    }
}
