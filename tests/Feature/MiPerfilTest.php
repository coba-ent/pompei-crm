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

    /** Spec 105 — los datos de contacto que se imprimen en el encabezado de los comprobantes. */
    public function test_guarda_telefono_y_sitio_web(): void
    {
        $this->post(route('configuracion.mi-perfil.guardar'), [
            'razon_social' => 'Pompei Sanitarios',
            'telefono' => '11 5555-5555 / WhatsApp 11 4444-4444',
            'sitio_web' => 'www.pompeisanitarios.com.ar',
        ])->assertOk()->assertJsonPath('ok', true);

        $datosEmpresa = DatosEmpresa::instancia();
        $this->assertSame('11 5555-5555 / WhatsApp 11 4444-4444', $datosEmpresa->telefono);
        $this->assertSame('www.pompeisanitarios.com.ar', $datosEmpresa->sitio_web);
    }

    /** FR-002: son opcionales — guardar sin ellos no puede fallar. */
    public function test_guarda_sin_telefono_ni_sitio_web(): void
    {
        $this->post(route('configuracion.mi-perfil.guardar'), [
            'razon_social' => 'Pompei Sanitarios',
        ])->assertOk()->assertJsonPath('ok', true);

        $datosEmpresa = DatosEmpresa::instancia();
        $this->assertNull($datosEmpresa->telefono);
        $this->assertNull($datosEmpresa->sitio_web);
    }

    /**
     * FR-010 / US2 escenario 4: agregar un campo nuevo no puede pisar lo que ya estaba cargado.
     *
     * Es el riesgo real de esta pantalla: guarda la ficha entera de una, así que un campo que no
     * viaja bien en el form se persiste vacío y el negocio pierde un dato sin enterarse.
     */
    public function test_cargar_el_telefono_no_pisa_los_datos_previos(): void
    {
        DatosEmpresa::create([
            'razon_social' => 'Pompei Sanitarios',
            'cuit' => '20111111112',
            'domicilio_fiscal' => 'Av. Siempre Viva 123',
        ]);

        $this->post(route('configuracion.mi-perfil.guardar'), [
            'razon_social' => 'Pompei Sanitarios',
            'cuit' => '20111111112',
            'domicilio_fiscal' => 'Av. Siempre Viva 123',
            'telefono' => '11 5555-5555',
        ])->assertOk();

        $datosEmpresa = DatosEmpresa::instancia();
        $this->assertSame('11 5555-5555', $datosEmpresa->telefono);
        $this->assertSame('20111111112', $datosEmpresa->cuit);
        $this->assertSame('Av. Siempre Viva 123', $datosEmpresa->domicilio_fiscal);
        $this->assertSame(1, DatosEmpresa::count(), 'La ficha de empresa es única: no se duplicó.');
    }
}
