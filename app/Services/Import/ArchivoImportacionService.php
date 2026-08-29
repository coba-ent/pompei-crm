<?php

namespace App\Services\Import;

use App\Models\ImportacionCorrida;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Conserva el archivo subido asociándolo a su corrida (spec 093, US2) y lo da de baja cuando
 * vence (US3).
 *
 * ⚠️ Guardar el archivo NO puede hacer fallar la importación (FR-016): `conservar()` no propaga
 * excepciones, sólo las registra. Un disco lleno no debe impedir actualizar precios.
 *
 * ⚠️ La copia se guarda con un nombre propio y único, no con `archivo_original`: dos corridas
 * pueden haber subido un archivo con el mismo nombre y cada una tiene que conservar el suyo
 * (FR-017). El nombre original vive en la columna `archivo_original` de la corrida y es el que se
 * usa al descargar.
 */
class ArchivoImportacionService
{
    /** Subdirectorio del disco `local` donde viven las copias conservadas. */
    public const DIRECTORIO = 'imports/guardados';

    /**
     * Copia el archivo temporal de una importación recién confirmada al almacén permanente y lo
     * registra en la corrida. Devuelve `true` si lo logró.
     *
     * @param  string  $rutaRelativaTemporal  ruta del temporal dentro del disco `local` (ej. `imports/<uuid>.xlsx`)
     */
    public function conservar(ImportacionCorrida $corrida, string $rutaRelativaTemporal): bool
    {
        try {
            $disco = Storage::disk('local');

            if (! $disco->exists($rutaRelativaTemporal)) {
                Log::warning('Import: no se pudo conservar el archivo, el temporal ya no está.', [
                    'corrida_id' => $corrida->id,
                    'ruta' => $rutaRelativaTemporal,
                ]);

                return false;
            }

            $extension = pathinfo((string) $corrida->archivo_original, PATHINFO_EXTENSION)
                ?: pathinfo($rutaRelativaTemporal, PATHINFO_EXTENSION)
                ?: 'xlsx';

            $destino = self::DIRECTORIO.'/'.$corrida->id.'-'.Str::uuid()->toString().'.'.$extension;

            if (! $disco->copy($rutaRelativaTemporal, $destino)) {
                Log::warning('Import: falló la copia del archivo a conservar.', ['corrida_id' => $corrida->id]);

                return false;
            }

            $corrida->update([
                'archivo_guardado_ruta' => $destino,
                'archivo_guardado_en' => now(),
                'archivo_vencido_en' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            // FR-016: se documenta, no se gatea. La importación ya terminó y es válida.
            Log::warning('Import: no se pudo conservar el archivo de la corrida.', [
                'corrida_id' => $corrida->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Elimina la copia conservada y marca la corrida como vencida, conservando el resto (FR-020). */
    public function vencer(ImportacionCorrida $corrida): void
    {
        if ($corrida->archivo_guardado_ruta) {
            Storage::disk('local')->delete($corrida->archivo_guardado_ruta);
        }

        $corrida->update([
            'archivo_guardado_ruta' => null,
            'archivo_vencido_en' => now(),
        ]);
    }
}
