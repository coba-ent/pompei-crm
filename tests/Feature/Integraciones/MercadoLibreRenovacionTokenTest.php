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
use ReflectionMethod;
use Tests\TestCase;

/**
 * US3/US5 (spec 011): renovación perezosa del access token. SC-004 es el
 * riesgo crítico de todo el módulo — el refresh_token de Mercado Libre es de
 * un solo uso, así que dos renovaciones concurrentes matan la conexión.
 */
class MercadoLibreRenovacionTokenTest extends TestCase
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
    }

    public function test_token_vencido_se_renueva_de_forma_transparente_antes_de_operar(): void
    {
        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-viejo',
            'refresh_token' => 'rtk-viejo',
            'token_expira_en' => now()->subMinute(),
            'vinculada_en' => now(),
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'atk-nuevo', 'token_type' => 'bearer', 'expires_in' => 10800,
                'user_id' => 1, 'refresh_token' => 'rtk-nuevo',
            ], 200),
            'api.mercadolibre.com/users/me' => Http::response(['id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA'], 200),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->exito);
        $cuenta->refresh();
        $this->assertSame('atk-nuevo', $cuenta->access_token);
        $this->assertSame('rtk-nuevo', $cuenta->refresh_token);
        $this->assertNotNull($cuenta->ultimo_refresh_en);
        Http::assertSentCount(2);
    }

    public function test_diez_intentos_concurrentes_con_token_vencido_producen_exactamente_una_renovacion(): void
    {
        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 999,
            'nickname' => 'CONCURRENCIA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vencido',
            'refresh_token' => 'rtk-original',
            'token_expira_en' => now()->subMinute(),
            'vinculada_en' => now(),
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'atk-renovado', 'token_type' => 'bearer', 'expires_in' => 10800,
                'user_id' => 999, 'refresh_token' => 'rtk-renovado',
            ], 200),
        ]);

        // Los 10 "procesos" leen la cuenta vencida casi al mismo tiempo, ANTES de que
        // cualquiera haya empezado a renovar — es la condición de carrera real.
        $instanciasStale = collect(range(1, 10))->map(fn () => MercadoLibreCuenta::find($cuenta->id));

        $metodo = new ReflectionMethod(ClienteMercadoLibre::class, 'asegurarTokenVigente');
        $metodo->setAccessible(true);
        $cliente = app(ClienteMercadoLibre::class);

        $resultados = $instanciasStale->map(fn ($instancia) => $metodo->invoke($cliente, $instancia));

        // Exactamente una renovación real contra el proveedor.
        Http::assertSentCount(1);

        // Ninguna operación falló por credencial inválida: las 10 terminaron con el
        // mismo token vigente, el emitido por la única renovación.
        foreach ($resultados as $resultado) {
            $this->assertSame('atk-renovado', $resultado->access_token);
            $this->assertFalse($resultado->tokenVencido(10));
        }

        $this->assertSame('rtk-renovado', $cuenta->fresh()->refresh_token);
    }

    public function test_renovacion_irrecuperable_marca_caida_sin_reintentos_posteriores(): void
    {
        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 2,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-viejo',
            'refresh_token' => 'rtk-invalido',
            'token_expira_en' => now()->subMinute(),
            'vinculada_en' => now(),
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['error' => 'invalid_grant', 'message' => 'invalid grant'], 400),
        ]);

        $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

        $this->assertTrue($respuesta->fallo());
        $this->assertSame(EstadoConexion::Caida, $cuenta->fresh()->estado);
        $this->assertNotNull($cuenta->fresh()->ultimo_error);

        // Una segunda operación no debe volver a intentar renovar: la cuenta ya no está conectada.
        Http::fake(['api.mercadolibre.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');
        Http::assertNothingSent();
    }

    public function test_sobrevive_a_3_renovaciones_consecutivas_manteniendo_la_cadena_de_refresh_token(): void
    {
        $cuenta = MercadoLibreCuenta::create([
            'ml_user_id' => 3,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-0',
            'refresh_token' => 'rtk-0',
            'token_expira_en' => now()->subMinute(),
            'vinculada_en' => now(),
        ]);

        // Http::fake() ACUMULA stubs entre llamadas (no reemplaza): con la misma URL
        // repetida por iteración, la primera respuesta registrada ganaría siempre.
        // Por eso se arma una única secuencia de 3 respuestas antes del loop.
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::sequence()
                ->push(['access_token' => 'atk-1', 'token_type' => 'bearer', 'expires_in' => 10800, 'user_id' => 3, 'refresh_token' => 'rtk-1'], 200)
                ->push(['access_token' => 'atk-2', 'token_type' => 'bearer', 'expires_in' => 10800, 'user_id' => 3, 'refresh_token' => 'rtk-2'], 200)
                ->push(['access_token' => 'atk-3', 'token_type' => 'bearer', 'expires_in' => 10800, 'user_id' => 3, 'refresh_token' => 'rtk-3'], 200),
            'api.mercadolibre.com/users/me' => Http::response(['id' => 3, 'nickname' => 'CUENTA', 'site_id' => 'MLA'], 200),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $respuesta = app(ClienteMercadoLibre::class)->obtener('probar_conexion', '/users/me');

            $this->assertTrue($respuesta->exito);
            $cuenta->refresh();
            $this->assertSame('atk-'.$i, $cuenta->access_token);
            $this->assertSame('rtk-'.$i, $cuenta->refresh_token);
            $this->assertSame(EstadoConexion::Conectada, $cuenta->estado);

            // fuerza el vencimiento para la próxima vuelta, simulando el paso del tiempo.
            // refresh() primero: sin esto, Eloquent compara contra los atributos originales
            // en memoria (pre-renovación) y puede no detectar el cambio como "dirty".
            $cuenta->refresh()->update(['token_expira_en' => now()->subMinute()]);
        }
    }
}
