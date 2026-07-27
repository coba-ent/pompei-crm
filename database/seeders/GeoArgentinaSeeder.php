<?php

namespace Database\Seeders;

use App\Models\Localidad;
use App\Models\Provincia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Puebla provincias y localidades de Argentina desde el bundle oficial georef
 * (database/data/ar_geo.json). Idempotente: no duplica si ya está cargado.
 */
class GeoArgentinaSeeder extends Seeder
{
    public function run(): void
    {
        if (Provincia::count() > 0 && Localidad::count() > 0) {
            return;
        }

        $ruta = database_path('data/ar_geo.json');
        if (! is_file($ruta)) {
            $this->command?->warn("No se encontró {$ruta}; se omite el seed geográfico.");

            return;
        }

        $data = json_decode(file_get_contents($ruta), true);
        $provincias = $data['provincias'] ?? [];
        $localidades = $data['localidades'] ?? [];

        // Provincias.
        $ahora = now();
        Provincia::upsert(
            array_map(fn ($nombre) => ['nombre' => $nombre, 'created_at' => $ahora, 'updated_at' => $ahora], $provincias),
            ['nombre'],
            ['updated_at']
        );

        $idPorProvincia = Provincia::pluck('id', 'nombre');

        // Localidades en lotes (son ~4.000).
        $filas = [];
        foreach ($localidades as $loc) {
            $provinciaId = $idPorProvincia[$loc['provincia']] ?? null;
            if (! $provinciaId) {
                continue;
            }
            $filas[] = [
                'provincia_id' => $provinciaId,
                'nombre' => $loc['nombre'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 1000) as $lote) {
            DB::table('localidades')->insert($lote);
        }

        $this->command?->info('Geografía AR: '.count($provincias).' provincias, '.count($filas).' localidades.');
    }
}
