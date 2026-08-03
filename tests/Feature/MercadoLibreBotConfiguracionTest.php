<?php

namespace Tests\Feature;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreBotConfiguracion;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 033, US1: switch "Bot de Mercado Libre" en Funciones Avanzadas
 * (FR-001/FR-002), guardado de instrucciones de tono (FR-003), y acceso
 * restringido por permiso (FR-012).
 */
class MercadoLibreBotConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new FuncionAvanzadaSeeder())->run();
        Permiso::updateOrCreate(
            ['codigo' => 'configuracion.funciones'],
            ['descripcion' => 'Funciones avanzadas', 'modulo' => 'configuracion']
        );
    }

    private function autenticarConPermiso(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($rol->id);
        $permiso = Permiso::where('codigo', 'configuracion.funciones')->first();
        $rol->permisos()->syncWithoutDetaching([$permiso->id]);
    }

    public function test_switch_activado_muestra_el_link_de_configuracion_del_bot(): void
    {
        $this->autenticarConPermiso();

        FuncionAvanzada::where('clave', 'mercadolibre_bot')->update(['activa' => true]);

        $respuesta = $this->get(route('configuracion.funciones.index'));

        $respuesta->assertOk();
        $respuesta->assertSee(route('configuracion.mercadolibre.bot'), false);
    }

    public function test_guardar_instrucciones_de_tono_persiste_el_cambio(): void
    {
        $this->autenticarConPermiso();

        $respuesta = $this->putJson(route('configuracion.mercadolibre.bot.guardar'), [
            'instrucciones_tono' => 'Tono formal, tratar de usted al comprador.',
        ]);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('Tono formal, tratar de usted al comprador.', MercadoLibreBotConfiguracion::actual()->instrucciones_tono);
    }

    public function test_usuario_sin_permiso_no_puede_guardar_configuracion(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $respuesta = $this->putJson(route('configuracion.mercadolibre.bot.guardar'), [
            'instrucciones_tono' => 'Intento sin permiso.',
        ]);

        $respuesta->assertForbidden();
    }
}
