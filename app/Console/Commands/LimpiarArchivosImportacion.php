<?php

namespace App\Console\Commands;

use App\Models\ImportacionCorrida;
use App\Services\Import\ArchivoImportacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Limpieza de archivos de importación vencidos (spec 093, US3). Agendada diaria.
 *
 * Elimina dos cosas:
 *   1. La copia conservada de las corridas cuyo `archivo_guardado_en` supera el plazo — la corrida
 *      queda marcada con `archivo_vencido_en` y conserva todos sus demás datos (FR-020).
 *   2. Los archivos **sueltos** de `imports/` sin corrida asociada y más viejos que el plazo — los
 *      23 huérfanos actuales (9,2 MB) entran acá; sus nombres son UUID que no quedaron registrados
 *      en ningún lado, así que no hay backfill posible (FR-021).
 *
 * ⚠️ NO toca los archivos de importaciones **en curso** (FR-022): una importación sin confirmar
 * tiene su temporal en el mismo directorio y borrarlo la rompería. El plazo de antigüedad es la
 * garantía — una importación en curso tiene un archivo de minutos, no de 90 días.
 *
 * ⚠️ Es destructiva y barre archivos que no están referenciados por ninguna fila: la primera
 * corrida en producción se hace con `--dry-run` y revisión de la lista.
 */
class LimpiarArchivosImportacion extends Command
{
    protected $signature = 'importaciones:limpiar-archivos {--dias= : Plazo en días (por defecto, el de config/importaciones.php)} {--dry-run : Lista lo que borraría sin borrar nada}';

    protected $description = 'Elimina los archivos de importación más viejos que el plazo de conservación (spec 093).';

    public function handle(ArchivoImportacionService $servicio): int
    {
        $dias = (int) ($this->option('dias') ?: config('importaciones.dias_conservacion_archivo', 90));
        $simulacion = (bool) $this->option('dry-run');
        $corte = now()->subDays($dias);

        $this->info("Plazo: {$dias} días — se elimina lo anterior a {$corte->format('d/m/Y H:i')}.");
        if ($simulacion) {
            $this->warn('MODO SIMULACIÓN (--dry-run): no se borra nada.');
        }

        $corridas = $this->limpiarCorridas($servicio, $corte, $simulacion);
        $huerfanos = $this->limpiarHuerfanos($corte, $simulacion);

        $this->newLine();
        $this->info(($simulacion ? 'Se eliminarían' : 'Se eliminaron')." {$corridas} archivos de corridas y {$huerfanos} archivos sueltos.");

        return self::SUCCESS;
    }

    /** Copias conservadas cuyo plazo venció. */
    private function limpiarCorridas(ArchivoImportacionService $servicio, $corte, bool $simulacion): int
    {
        $vencidas = ImportacionCorrida::query()
            ->whereNotNull('archivo_guardado_ruta')
            ->whereNull('archivo_vencido_en')
            ->where('archivo_guardado_en', '<', $corte)
            ->get();

        foreach ($vencidas as $corrida) {
            $this->line("  corrida #{$corrida->id} — {$corrida->archivo_original} ({$corrida->archivo_guardado_ruta})");

            if (! $simulacion) {
                $servicio->vencer($corrida);
            }
        }

        return $vencidas->count();
    }

    /**
     * Archivos sueltos de `imports/` que ninguna corrida referencia y superan el plazo. La
     * antigüedad es lo que protege a las importaciones en curso (FR-022).
     */
    private function limpiarHuerfanos($corte, bool $simulacion): int
    {
        $disco = Storage::disk('local');

        if (! $disco->exists('imports')) {
            return 0;
        }

        // Las rutas referenciadas por alguna corrida NO son huérfanas, por más viejas que sean:
        // vencen por su propio camino (`limpiarCorridas`), no por éste.
        $referenciadas = ImportacionCorrida::whereNotNull('archivo_guardado_ruta')
            ->pluck('archivo_guardado_ruta')->flip();

        $eliminados = 0;
        $limite = $corte->getTimestamp();

        foreach ($disco->allFiles('imports') as $ruta) {
            if ($referenciadas->has($ruta)) {
                continue;
            }

            if ($disco->lastModified($ruta) >= $limite) {
                continue; // dentro del plazo — puede ser una importación en curso (FR-022)
            }

            $this->line('  suelto — '.$ruta.' ('.round($disco->size($ruta) / 1024).' KB)');

            if (! $simulacion) {
                $disco->delete($ruta);
            }

            $eliminados++;
        }

        return $eliminados;
    }
}
