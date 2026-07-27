<?php

namespace Tests\Feature;

use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US4: los toggles de Funciones Avanzadas, persistidos en `empresa`, deben reflejarse en
 * config('negocio.*') y en las rutas gateadas (009/010) en el request siguiente, sin recarga
 * manual de config (FR-023/024, T055).
 */
class FuncionesAvanzadasControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $condicion = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        Empresa::create([
            'razon_social' => 'Emisor de Prueba',
            'cuit' => '20111111112',
            'condicion_iva_id' => $condicion->id,
            'ambiente_arca' => 'testing',
        ]);
    }

    public function test_activar_abonos_habilita_la_ruta_gateada_en_el_siguiente_request(): void
    {
        $this->getJson(route('abonos.index'))->assertForbidden();

        $this->patchJson(route('configuracion.funciones-avanzadas.update'), ['abonos_habilitados' => true])
            ->assertOk();

        $this->getJson(route('abonos.index'))->assertOk();
    }

    public function test_desactivar_facturacion_electronica_bloquea_sus_rutas(): void
    {
        $this->patchJson(route('configuracion.funciones-avanzadas.update'), ['facturacion_electronica_habilitada' => true])
            ->assertOk();
        $this->getJson(route('puntos-venta.index'))->assertOk();

        $this->patchJson(route('configuracion.funciones-avanzadas.update'), ['facturacion_electronica_habilitada' => false])
            ->assertOk();
        $this->getJson(route('puntos-venta.index'))->assertForbidden();
    }

    public function test_actualizar_retenciones_persiste_directo_en_empresa(): void
    {
        $this->patchJson(route('configuracion.funciones-avanzadas.update'), ['retenciones_habilitadas' => true])
            ->assertOk()
            ->assertJsonPath('funciones.retenciones_habilitadas', true);

        $this->assertTrue(Empresa::actual()->retenciones_habilitadas);
    }
}
