<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\MercadoLibre\SincronizadorStockFull;
use App\Services\MercadoLibre\SincronizadorTiposPublicacion;
use Illuminate\Console\Command;
use Throwable;

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

    public function __construct(
        private readonly SincronizadorStockFull $sincronizadorFull,
        private readonly SincronizadorTiposPublicacion $sincronizadorTipos,
    ) {
        parent::__construct();
    }

    public function handle(SincronizadorStock $sincronizador): int
    {
        // spec 116 (FR-012/FR-013): la revisión de tipos va colgada de este cron, en todos los caminos
        // —también con el stock bloqueado, sin corresponder o roto (ej. sin depósito)—, porque tiene su
        // propio freno por la misma `frecuencia_sync_minutos`. Su resultado no cambia el del stock.
        try {
            return $this->sincronizarStock($sincronizador);
        } finally {
            $this->revisarTipos();
        }
    }

    private function revisarTipos(): void
    {
        try {
            $resultado = $this->sincronizadorTipos->sincronizarSiCorresponde();
        } catch (Throwable $e) {
            report($e);
            $this->warn('No se pudo revisar el tipo de las publicaciones: '.$e->getMessage());

            return;
        }

        if ($resultado === null) {
            return;
        }

        $this->info("Tipos de publicación: {$resultado['actualizados']} actualizados, {$resultado['con_error']} con error, "
            ."{$resultado['cambios_procesados']} cambios hechos en Mercado Libre procesados, {$resultado['cambios_postergados']} postergados.");
    }

    private function sincronizarStock(SincronizadorStock $sincronizador): int
    {
        $configuracion = MercadoLibreConfiguracion::actual();

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

        // spec 065/T028: el reflejo ML → CRM va DESPUÉS del push, para que el push trabaje
        // con el stock que el CRM tenía y no con el que se acaba de traer de Mercado Libre.
        // No cambia el código de salida: que el reflejo Full no opere (por ejemplo, por
        // falta de depósito) no convierte en fallida una corrida de stock que salió bien.
        $resultadoFull = $this->sincronizadorFull->ejecutar();
        $resultadoFull['ok'] ? $this->info($resultadoFull['mensaje']) : $this->warn($resultadoFull['mensaje']);

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
