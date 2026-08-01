<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Enums\Tiendanube\EstadoConexion as EstadoConexionTiendanube;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US1 (spec 011): pantalla "Funciones Avanzadas" — 10 tarjetas, toggle persistido,
 * validación de disponibilidad en servidor (FR-004), permiso, auditoría (FR-008)
 * y confirmación al desactivar Mercado Libre con una cuenta conectada (FR-005a).
 */
class FuncionesAvanzadasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new FuncionAvanzadaSeeder())->run();
    }

    private function darPermisoAlUsuarioActual(): void
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_la_pantalla_lista_las_10_funciones_en_el_orden_relevado(): void
    {
        $this->darPermisoAlUsuarioActual();

        $response = $this->get(route('configuracion.funciones.index'));

        $response->assertOk();
        $response->assertViewHas('funciones', function ($funciones) {
            return $funciones->count() === 10
                && $funciones->pluck('clave')->all() === [
                    'facturacion_electronica', 'mercadolibre', 'tiendanube', 'reportes_email',
                    'abonos', 'ia', 'retenciones', 'ventas_sin_stock', 'depositos', 'lector_codigo_barras',
                ];
        });
    }

    public function test_el_toggle_de_una_funcion_disponible_persiste(): void
    {
        $this->darPermisoAlUsuarioActual();

        $funcion = FuncionAvanzada::where('clave', 'abonos')->firstOrFail();
        $this->assertTrue($funcion->activa);

        $response = $this->patchJson(route('configuracion.funciones.estado', $funcion), ['activa' => false]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('funcion.activa', false);
        $this->assertFalse($funcion->fresh()->activa);
    }

    /** T020 (spec 015): la tarjeta "Tiendanube" queda disponible=true tras el seeder. */
    public function test_la_tarjeta_tiendanube_aparece_disponible(): void
    {
        $funcion = FuncionAvanzada::where('clave', 'tiendanube')->firstOrFail();

        $this->assertTrue($funcion->disponible);
        $this->assertFalse($funcion->activa);
        $this->assertSame('configuracion.tiendanube.index', $funcion->ruta_configuracion);
    }

    public function test_activar_una_funcion_no_disponible_devuelve_422(): void
    {
        $this->darPermisoAlUsuarioActual();

        $funcion = FuncionAvanzada::where('clave', 'facturacion_electronica')->firstOrFail();

        $response = $this->patchJson(route('configuracion.funciones.estado', $funcion), ['activa' => true]);

        $response->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertFalse($funcion->fresh()->activa);
    }

    public function test_un_usuario_sin_permiso_recibe_403(): void
    {
        // No se otorga el permiso: el usuario autenticado por defecto de TestCase no tiene roles.
        $response = $this->get(route('configuracion.funciones.index'));

        $response->assertStatus(403);
    }

    public function test_el_toggle_registra_quien_y_cuando(): void
    {
        $this->darPermisoAlUsuarioActual();
        $usuario = auth()->user();

        $funcion = FuncionAvanzada::where('clave', 'abonos')->firstOrFail();

        $this->patchJson(route('configuracion.funciones.estado', $funcion), ['activa' => false])->assertOk();

        $funcion->refresh();
        $this->assertSame($usuario->id, $funcion->actualizada_por);
        $this->assertNotNull($funcion->actualizada_en);
    }

    public function test_desactivar_mercadolibre_con_cuenta_conectada_exige_confirmacion(): void
    {
        $this->darPermisoAlUsuarioActual();

        $funcion = FuncionAvanzada::where('clave', 'mercadolibre')->firstOrFail();
        $funcion->update(['activa' => true]);

        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 555,
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk',
            'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3),
        ]);

        // Sin confirmar: 409, ni la función ni la cuenta cambian.
        $sinConfirmar = $this->patchJson(route('configuracion.funciones.estado', $funcion), ['activa' => false]);
        $sinConfirmar->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('requiere_confirmacion', true);

        $this->assertTrue($funcion->fresh()->activa);
        $this->assertSame(EstadoConexion::Conectada, $cuenta->fresh()->estado);

        // Confirmando: se desactiva la función, pero la vinculación se conserva.
        $confirmado = $this->patchJson(route('configuracion.funciones.estado', $funcion), [
            'activa' => false,
            'confirmado' => true,
        ]);
        $confirmado->assertOk()->assertJsonPath('funcion.activa', false);

        $this->assertFalse($funcion->fresh()->activa);
        $this->assertSame(EstadoConexion::Conectada, $cuenta->fresh()->estado);
        $this->assertNotNull($cuenta->fresh()->access_token);
    }

    /** FR-006a (spec 015): mismo patrón que Mercado Libre, tabla y conexión propias. */
    public function test_desactivar_tiendanube_con_conexion_activa_exige_confirmacion(): void
    {
        $this->darPermisoAlUsuarioActual();

        $funcion = FuncionAvanzada::where('clave', 'tiendanube')->firstOrFail();
        $funcion->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente',
            'estado' => EstadoConexionTiendanube::Conectada,
        ]);

        $sinConfirmar = $this->patchJson(route('configuracion.funciones.estado', $funcion), ['activa' => false]);
        $sinConfirmar->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('requiere_confirmacion', true);

        $this->assertTrue($funcion->fresh()->activa);
        $this->assertSame(EstadoConexionTiendanube::Conectada, TiendanubeConexionRest::actual()->estado);

        $confirmado = $this->patchJson(route('configuracion.funciones.estado', $funcion), [
            'activa' => false,
            'confirmado' => true,
        ]);
        $confirmado->assertOk()->assertJsonPath('funcion.activa', false);

        $this->assertFalse($funcion->fresh()->activa);
        $this->assertSame(EstadoConexionTiendanube::Conectada, TiendanubeConexionRest::actual()->estado);
        $this->assertNotNull(TiendanubeConexionRest::actual()->access_token);
    }
}
