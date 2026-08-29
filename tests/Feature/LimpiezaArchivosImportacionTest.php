<?php

namespace Tests\Feature;

use App\Models\ImportacionCorrida;
use App\Services\Import\ArchivoImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec 093, US3 — limpieza de los archivos de importación vencidos y de los sueltos. */
class LimpiezaArchivosImportacionTest extends TestCase
{
    use RefreshDatabase;

    private function corrida(array $atributos = []): ImportacionCorrida
    {
        return ImportacionCorrida::create(array_merge([
            'entidad' => 'productos',
            'archivo_original' => 'productos.xlsx',
            'confirmado_en' => now(),
            'deshacer_disponible_hasta' => now()->addHours(48),
            'filas_creadas' => 0,
            'filas_actualizadas' => 0,
            'filas_fallidas' => 0,
        ], $atributos));
    }

    /** Conserva un archivo para la corrida y antedata su `archivo_guardado_en`. */
    private function conservar(ImportacionCorrida $corrida, string $nombre, ?int $diasAtras = null): string
    {
        Storage::disk('local')->put("imports/{$nombre}", 'contenido');
        app(ArchivoImportacionService::class)->conservar($corrida, "imports/{$nombre}");

        if ($diasAtras !== null) {
            $corrida->update(['archivo_guardado_en' => now()->subDays($diasAtras)]);
        }

        return $corrida->fresh()->archivo_guardado_ruta;
    }

    // ---------------------------------------------------------------------------------------
    // T019 — lo vencido se borra, lo vigente no
    // ---------------------------------------------------------------------------------------

    public function test_borra_lo_vencido_y_marca_la_corrida(): void
    {
        Storage::fake('local');

        $vieja = $this->corrida();
        $ruta = $this->conservar($vieja, 'vieja.xlsx', 120);

        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($ruta));

        $vieja = $vieja->fresh();
        $this->assertSame('vencido', $vieja->estadoArchivo());
        $this->assertNotNull($vieja->archivo_vencido_en);
        // FR-020: conserva todos sus demás datos.
        $this->assertSame('productos.xlsx', $vieja->archivo_original);
        $this->assertNotNull($vieja->confirmado_en);
    }

    public function test_no_toca_lo_que_esta_dentro_del_plazo(): void
    {
        Storage::fake('local');

        $reciente = $this->corrida();
        $ruta = $this->conservar($reciente, 'reciente.xlsx', 10);

        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($ruta));
        $this->assertSame('disponible', $reciente->fresh()->estadoArchivo());
    }

    /**
     * FR-022: una importación **en curso** tiene su temporal en el mismo directorio; borrarlo la
     * rompería. La antigüedad es lo que la protege — un archivo en curso tiene minutos, no 90 días.
     */
    public function test_no_toca_archivos_de_importaciones_sin_confirmar(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('imports/en-curso.xlsx', 'importación a mitad de camino');

        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists('imports/en-curso.xlsx'));
    }

    // ---------------------------------------------------------------------------------------
    // T020 — los sueltos sin corrida
    // ---------------------------------------------------------------------------------------

    /** FR-021: los 23 huérfanos actuales entran por acá — nombres UUID sin registro en ningún lado. */
    public function test_los_archivos_sueltos_sin_corrida_tambien_se_eliminan(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('imports/huerfano.xlsx', 'suelto y viejo');
        touch(Storage::disk('local')->path('imports/huerfano.xlsx'), now()->subDays(120)->getTimestamp());

        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists('imports/huerfano.xlsx'));
    }

    /** Un archivo referenciado por una corrida NO es huérfano, por más viejo que sea el archivo. */
    public function test_un_archivo_referenciado_no_se_borra_como_huerfano(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        $ruta = $this->conservar($corrida, 'referenciado.xlsx');
        touch(Storage::disk('local')->path($ruta), now()->subDays(200)->getTimestamp());

        // `archivo_guardado_en` es de hoy: está dentro del plazo por su propio camino.
        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($ruta));
        $this->assertSame('disponible', $corrida->fresh()->estadoArchivo());
    }

    // ---------------------------------------------------------------------------------------
    // --dias y --dry-run
    // ---------------------------------------------------------------------------------------

    public function test_dry_run_lista_sin_borrar_nada(): void
    {
        Storage::fake('local');

        $vieja = $this->corrida();
        $ruta = $this->conservar($vieja, 'vieja.xlsx', 120);
        Storage::disk('local')->put('imports/huerfano.xlsx', 'suelto');
        touch(Storage::disk('local')->path('imports/huerfano.xlsx'), now()->subDays(120)->getTimestamp());

        $this->artisan('importaciones:limpiar-archivos --dry-run')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($ruta));
        $this->assertTrue(Storage::disk('local')->exists('imports/huerfano.xlsx'));
        $this->assertSame('disponible', $vieja->fresh()->estadoArchivo());
    }

    /** FR-019: el plazo es configurable; 90 días es sólo el valor por defecto. */
    public function test_el_plazo_se_puede_ajustar_con_dias(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        $ruta = $this->conservar($corrida, 'archivo.xlsx', 30);

        // Con el plazo por defecto (90) sobrevive.
        $this->artisan('importaciones:limpiar-archivos')->assertSuccessful();
        $this->assertTrue(Storage::disk('local')->exists($ruta));

        // Con 7 días, no.
        $this->artisan('importaciones:limpiar-archivos --dias=7')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($ruta));
        $this->assertSame('vencido', $corrida->fresh()->estadoArchivo());
    }

    /** El plazo por defecto sale de la configuración y son 90 días. */
    public function test_el_plazo_por_defecto_es_de_90_dias(): void
    {
        $this->assertSame(90, config('importaciones.dias_conservacion_archivo'));
    }
}
