<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Rol;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US4 (spec 011): kill-switch de sólo lectura (SC-005) e historial. R7: la
 * verificación vive en un único punto (ClienteMercadoLibre::peticion()) —
 * es lo que hace garantizable que ninguna escritura se filtre.
 */
class MercadoLibreModoSoloLecturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk',
            'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    public function test_con_el_modo_activo_ninguna_escritura_alcanza_a_mercado_libre(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(); // cualquier request real sería un bug.

        $respuesta = app(ClienteMercadoLibre::class)->enviar('publicar_item', 'POST', '/items', ['title' => 'Producto de prueba']);

        Http::assertNothingSent();
        $this->assertTrue($respuesta->fueBloqueada());
        $this->assertTrue($respuesta->fallo());

        $registro = MercadoLibreOperacionLog::latest('id')->first();
        $this->assertSame('bloqueada', $registro->resultado);
        $this->assertNotNull($registro->payload_bloqueado);
        $this->assertStringContainsString('Producto de prueba', $registro->payload_bloqueado);
    }

    public function test_las_lecturas_siguen_funcionando_con_el_modo_activo(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake(['api.mercadolibre.com/users/me' => Http::response(['id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA'], 200)]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        Http::assertSentCount(1);
        $this->assertTrue($respuesta->exito);
    }

    public function test_el_cambio_del_interruptor_tiene_efecto_inmediato(): void
    {
        Http::fake(['api.mercadolibre.com/items' => Http::response(['id' => 'MLA123'], 201)]);

        $respuesta1 = app(ClienteMercadoLibre::class)->enviar('publicar_item', 'POST', '/items', []);
        $this->assertFalse($respuesta1->fueBloqueada());

        $this->patchJson(route('configuracion.mercadolibre.modoSoloLectura'), ['activo' => true])
            ->assertOk()->assertJsonPath('modo_solo_lectura', true);

        $respuesta2 = app(ClienteMercadoLibre::class)->enviar('publicar_item', 'POST', '/items', []);
        $this->assertTrue($respuesta2->fueBloqueada());
    }

    public function test_la_retencion_no_borra_registros_dentro_de_la_ventana(): void
    {
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'probar_conexion', 'metodo' => 'GET', 'endpoint' => '/users/me',
            'sentido' => 'lectura', 'resultado' => 'exito', 'codigo_http' => 200, 'duracion_ms' => 100,
            'created_at' => now()->subDays(10),
        ]);
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'probar_conexion', 'metodo' => 'GET', 'endpoint' => '/users/me',
            'sentido' => 'lectura', 'resultado' => 'exito', 'codigo_http' => 200, 'duracion_ms' => 100,
            'created_at' => now()->subDays(40), // fuera de la ventana de 30 días
        ]);

        // Fuerza la depuración oportunista (normalmente ~1 de cada 50 inserciones).
        $reflexion = new \ReflectionMethod(MercadoLibreOperacionLog::class, 'depurarPorRetencion');
        $reflexion->setAccessible(true);
        $reflexion->invoke(null);

        $this->assertDatabaseHas('ml_operaciones_log', ['duracion_ms' => 100, 'created_at' => now()->subDays(10)->toDateTimeString()]);
        $this->assertSame(1, MercadoLibreOperacionLog::count());
    }
}
