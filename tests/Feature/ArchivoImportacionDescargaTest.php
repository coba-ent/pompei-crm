<?php

namespace Tests\Feature;

use App\Models\ImportacionCorrida;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Import\ArchivoImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec 093, US2 — conservar el archivo subido y poder descargarlo desde el historial. */
class ArchivoImportacionDescargaTest extends TestCase
{
    use RefreshDatabase;

    private function corrida(array $atributos = []): ImportacionCorrida
    {
        return ImportacionCorrida::create(array_merge([
            'entidad' => 'productos',
            'archivo_original' => 'productos_20260825_175146.xlsx',
            'confirmado_en' => now(),
            'deshacer_disponible_hasta' => now()->addHours(48),
            'filas_creadas' => 0,
            'filas_actualizadas' => 0,
            'filas_fallidas' => 0,
        ], $atributos));
    }

    /** Usuario con el permiso de importaciones (FR-014): la descarga no inventa un permiso nuevo. */
    private function usuarioConPermiso(): User
    {
        $usuario = User::factory()->create();
        $rol = Rol::create(['nombre' => 'Importador', 'descripcion' => 'test']);
        $permiso = Permiso::firstOrCreate(
            ['codigo' => 'configuracion.importar'],
            ['modulo' => 'configuracion', 'accion' => 'importar', 'descripcion' => 'Importar Datos por Excel'],
        );
        $rol->permisos()->sync([$permiso->id]);
        $usuario->roles()->sync([$rol->id]);

        return $usuario->fresh();
    }

    private function temporal(string $contenido = 'contenido-xlsx'): string
    {
        Storage::disk('local')->put('imports/temporal.xlsx', $contenido);

        return 'imports/temporal.xlsx';
    }

    // ---------------------------------------------------------------------------------------
    // T013 — la descarga y sus códigos
    // ---------------------------------------------------------------------------------------

    public function test_descarga_el_archivo_con_su_nombre_original(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        app(ArchivoImportacionService::class)->conservar($corrida, $this->temporal());

        $respuesta = $this->actingAs($this->usuarioConPermiso())
            ->get("/importar-datos/productos/historial/{$corrida->id}/archivo")
            ->assertOk();

        $this->assertStringContainsString('productos_20260825_175146.xlsx', $respuesta->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', $respuesta->headers->get('content-disposition'));
        // Nunca un archivo vacío.
        $this->assertNotSame('', $respuesta->streamedContent());
    }

    public function test_sin_permiso_da_403(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        app(ArchivoImportacionService::class)->conservar($corrida, $this->temporal());

        $this->actingAs(User::factory()->create())
            ->get("/importar-datos/productos/historial/{$corrida->id}/archivo")
            ->assertForbidden();
    }

    /** 410 y no 404 a propósito: existió y ya no está, que es información útil para quien audita. */
    public function test_archivo_vencido_da_410(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        app(ArchivoImportacionService::class)->conservar($corrida, $this->temporal());
        app(ArchivoImportacionService::class)->vencer($corrida);

        $this->actingAs($this->usuarioConPermiso())
            ->getJson("/importar-datos/productos/historial/{$corrida->id}/archivo")
            ->assertStatus(410);
    }

    /** Registrado pero ilegible (borrado a mano, corrupto): 422, nunca un archivo vacío. */
    public function test_archivo_registrado_pero_ilegible_da_422(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();
        app(ArchivoImportacionService::class)->conservar($corrida, $this->temporal());

        Storage::disk('local')->delete($corrida->fresh()->archivo_guardado_ruta);

        $this->actingAs($this->usuarioConPermiso())
            ->getJson("/importar-datos/productos/historial/{$corrida->id}/archivo")
            ->assertStatus(422);
    }

    public function test_corrida_inexistente_da_404(): void
    {
        Storage::fake('local');

        $this->actingAs($this->usuarioConPermiso())
            ->getJson('/importar-datos/productos/historial/99999/archivo')
            ->assertNotFound();
    }

    public function test_corrida_sin_archivo_nunca_guardado_da_404(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();

        $this->actingAs($this->usuarioConPermiso())
            ->getJson("/importar-datos/productos/historial/{$corrida->id}/archivo")
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------
    // T014 — mismo nombre de archivo, copias distintas
    // ---------------------------------------------------------------------------------------

    /** FR-017: `archivo_original` NO es la clave — se repite entre corridas. */
    public function test_dos_corridas_con_el_mismo_nombre_conservan_cada_una_su_copia(): void
    {
        Storage::fake('local');
        $servicio = app(ArchivoImportacionService::class);

        $primera = $this->corrida();
        Storage::disk('local')->put('imports/t1.xlsx', 'contenido-de-la-primera');
        $servicio->conservar($primera, 'imports/t1.xlsx');

        $segunda = $this->corrida(); // mismo `archivo_original`
        Storage::disk('local')->put('imports/t2.xlsx', 'contenido-de-la-segunda');
        $servicio->conservar($segunda, 'imports/t2.xlsx');

        $rutaPrimera = $primera->fresh()->archivo_guardado_ruta;
        $rutaSegunda = $segunda->fresh()->archivo_guardado_ruta;

        $this->assertNotSame($rutaPrimera, $rutaSegunda);
        $this->assertSame('contenido-de-la-primera', Storage::disk('local')->get($rutaPrimera));
        $this->assertSame('contenido-de-la-segunda', Storage::disk('local')->get($rutaSegunda));
    }

    // ---------------------------------------------------------------------------------------
    // T015 — el guardado no puede hacer fallar la importación
    // ---------------------------------------------------------------------------------------

    /**
     * FR-016. Es la garantía de que un disco lleno no impida actualizar precios: si el temporal ya
     * no está, `conservar()` devuelve false y registra, pero no lanza.
     */
    public function test_si_el_guardado_falla_no_lanza_y_la_corrida_queda_sin_archivo(): void
    {
        Storage::fake('local');

        $corrida = $this->corrida();

        $resultado = app(ArchivoImportacionService::class)->conservar($corrida, 'imports/no-existe.xlsx');

        $this->assertFalse($resultado);
        $this->assertNull($corrida->fresh()->archivo_guardado_ruta);
        // "Nunca se guardó" y NO "venció": son cosas distintas (FR-015).
        $this->assertSame('nunca_guardado', $corrida->fresh()->estadoArchivo());
    }

    // ---------------------------------------------------------------------------------------
    // FR-015 — los tres estados en el historial
    // ---------------------------------------------------------------------------------------

    public function test_el_historial_distingue_los_tres_estados_del_archivo(): void
    {
        Storage::fake('local');
        $servicio = app(ArchivoImportacionService::class);

        $nuncaGuardado = $this->corrida();

        $disponible = $this->corrida();
        Storage::disk('local')->put('imports/ok.xlsx', 'x');
        $servicio->conservar($disponible, 'imports/ok.xlsx');

        $vencido = $this->corrida();
        Storage::disk('local')->put('imports/viejo.xlsx', 'x');
        $servicio->conservar($vencido, 'imports/viejo.xlsx');
        $servicio->vencer($vencido);

        $data = $this->actingAs($this->usuarioConPermiso())
            ->getJson('/importar-datos/productos/historial/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data');

        $porId = collect($data)->keyBy('id');

        $this->assertSame('nunca_guardado', $porId[$nuncaGuardado->id]['archivo']['estado']);
        $this->assertFalse($porId[$nuncaGuardado->id]['archivo']['descargable']);

        $this->assertSame('disponible', $porId[$disponible->id]['archivo']['estado']);
        $this->assertTrue($porId[$disponible->id]['archivo']['descargable']);

        $this->assertSame('vencido', $porId[$vencido->id]['archivo']['estado']);
        $this->assertFalse($porId[$vencido->id]['archivo']['descargable']);
        $this->assertNotNull($porId[$vencido->id]['archivo']['vencido_en']);
    }
}
