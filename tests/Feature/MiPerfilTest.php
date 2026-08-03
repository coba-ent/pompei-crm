<?php

namespace Tests\Feature;

use App\Models\CondicionIva;
use App\Models\DatosEmpresa;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** US2 (spec 039) — Mi Perfil persiste datos y logo; rechaza un logo inválido (FR-014). */
class MiPerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_guardar_mi_perfil_persiste_los_datos_y_el_logo(): void
    {
        Storage::fake('public');
        CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);

        $response = $this->post(route('configuracion.mi-perfil.guardar'), [
            'razon_social' => 'Pompei Sanitarios',
            'cuit' => '20111111112',
            'domicilio_fiscal' => 'Av. Siempre Viva 123',
            'condicion_iva' => 'Responsable Inscripto',
            'ingresos_brutos' => '901-123456-7',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $datosEmpresa = DatosEmpresa::instancia();
        $this->assertNotNull($datosEmpresa);
        $this->assertSame('Pompei Sanitarios', $datosEmpresa->razon_social);
        $this->assertSame('20111111112', $datosEmpresa->cuit);
        $this->assertNotNull($datosEmpresa->ruta_logo);
        Storage::disk('public')->assertExists($datosEmpresa->ruta_logo);
    }

    public function test_rechaza_logo_invalido_sin_persistir(): void
    {
        Storage::fake('public');

        $response = $this->postJson(route('configuracion.mi-perfil.guardar'), [
            'razon_social' => 'Pompei Sanitarios',
            'logo' => UploadedFile::fake()->create('archivo.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $this->assertNull(DatosEmpresa::instancia());
    }
}
