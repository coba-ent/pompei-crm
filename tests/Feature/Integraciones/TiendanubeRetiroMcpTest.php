<?php

namespace Tests\Feature\Integraciones;

use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 024, US3 (T023): smoke test post-retiro del MCP — confirma que las
 * clases de la conexión OAuth/MCP (spec 019) ya no existen en el árbol de
 * clases cargado y que `GET tiendanube` sólo expone el apartado REST
 * (spec 022/024).
 */
class TiendanubeRetiroMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_las_clases_del_mcp_ya_no_existen(): void
    {
        $this->assertFalse(class_exists(\App\Services\Tiendanube\ClienteTiendanube::class));
        $this->assertFalse(class_exists(\App\Http\Controllers\Integraciones\TiendanubeOAuthController::class));
        $this->assertFalse(class_exists(\App\Services\Tiendanube\RegistradorClienteOAuth::class));
        $this->assertFalse(class_exists(\App\Models\Integraciones\TiendanubeConfiguracion::class));
        $this->assertFalse(class_exists(\App\Models\Integraciones\TiendanubeOperacionLog::class));
    }

    public function test_las_tablas_del_mcp_ya_no_existen(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('tn_configuracion'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('tn_operaciones_log'));
    }

    public function test_la_pantalla_de_configuracion_solo_muestra_el_apartado_rest(): void
    {
        $respuesta = $this->get(route('configuracion.tiendanube.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('Conexión con Tiendanube');
        $respuesta->assertDontSee('tn-panel-estado', false);
        $respuesta->assertDontSee('modal-desconectar-tn"', false);
    }

    public function test_las_rutas_de_conexion_mcp_ya_no_estan_registradas(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('configuracion.tiendanube.estado'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('configuracion.tiendanube.desconectar'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('configuracion.tiendanube.conectar'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('configuracion.tiendanube.callback'));
    }
}
