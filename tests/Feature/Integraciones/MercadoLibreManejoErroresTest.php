<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Rol;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 011): política de reintentos de contracts/api-mercadolibre.md,
 * implementada una única vez en ClienteMercadoLibre (R8). El punto más sutil
 * es no confundir 401 (credencial vencida, renueva) con 403 (falta de
 * permiso funcional, NO renueva).
 */
class MercadoLibreManejoErroresTest extends TestCase
{
    use RefreshDatabase;

    private MercadoLibreCuenta $cuenta;

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

        // Token vigente (no vencido): así el retry-loop se ejercita directamente, sin
        // pasar primero por la renovación perezosa de asegurarTokenVigente().
        $this->cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente',
            'refresh_token' => 'rtk-vigente',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    public function test_401_renueva_y_reintenta_una_vez(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::sequence()
                ->push(['message' => 'invalid token'], 401)
                ->push(['id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA'], 200),
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'atk-renovado', 'token_type' => 'bearer', 'expires_in' => 10800,
                'user_id' => 1, 'refresh_token' => 'rtk-renovado',
            ], 200),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->exito);
        Http::assertSentCount(3); // GET(401) + POST /oauth/token + GET reintentado (200)
        $this->assertSame('atk-renovado', $this->cuenta->fresh()->access_token);
        $this->assertSame(EstadoConexion::Conectada, $this->cuenta->fresh()->estado);
    }

    public function test_401_persistente_tras_reintento_marca_caida(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::response(['message' => 'invalid token'], 401),
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'atk-renovado', 'token_type' => 'bearer', 'expires_in' => 10800,
                'user_id' => 1, 'refresh_token' => 'rtk-renovado',
            ], 200),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(3); // GET(401) + POST /oauth/token + GET reintentado (401 de nuevo)
        $this->assertSame(EstadoConexion::Caida, $this->cuenta->fresh()->estado);
    }

    public function test_403_no_dispara_renovacion_es_falta_de_permiso_no_credencial_vencida(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::response(['error' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(1); // sin reintento y SIN renovación
        $this->assertSame(EstadoConexion::Conectada, $this->cuenta->fresh()->estado);
        $this->assertStringContainsString('permiso', $respuesta->mensajeError);
    }

    public function test_429_aplica_espera_creciente_hasta_3_intentos(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::response(['error' => 'local_rate_limited'], 429),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(4); // intento original + 3 reintentos
        $this->assertSame(EstadoConexion::Conectada, $this->cuenta->fresh()->estado);
    }

    public function test_5xx_reintenta_sin_marcar_la_conexion_como_caida(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::sequence()
                ->push(['error' => 'internal'], 500)
                ->push(['error' => 'internal'], 500)
                ->push(['id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA'], 200),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->exito);
        Http::assertSentCount(3);
        $this->assertSame(EstadoConexion::Conectada, $this->cuenta->fresh()->estado);
    }
}
