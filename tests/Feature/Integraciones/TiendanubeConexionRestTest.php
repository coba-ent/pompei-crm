<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1/US2 (spec 022): conexión OAuth clásica contra la Application REST del
 * Partner Portal, panel de estado propio y desconexión — todo aislado de la
 * conexión MCP de spec 019 (TiendanubeConexionRestAislamientoTest cubre el
 * aislamiento en sí). Todo con Http::fake() — ningún test llama de verdad a
 * www.tiendanube.com ni api.tiendanube.com (spec.md, restricción crítica).
 */
class TiendanubeConexionRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        config([
            'integraciones.tiendanube.client_id' => '38015',
            'integraciones.tiendanube.client_secret' => 'client-secret-de-prueba',
        ]);
    }

    private function fakeFlujoCompletoExitoso(array $overridesToken = [], array $overridesStore = []): void
    {
        Http::fake([
            'www.tiendanube.com/apps/authorize/token' => Http::response(array_merge([
                'access_token' => 'token-rest-de-prueba',
                'token_type' => 'bearer',
                'scope' => 'read_products,write_products,read_orders',
                'user_id' => 6922207,
            ], $overridesToken), 200),
            'api.tiendanube.com/v1/*/store' => Http::response(array_merge([
                'id' => 6922207,
                'name' => ['es' => 'Pompei Sanitarios'],
                'url' => 'https://pompeisanitarios.com',
                'original_domain' => 'pompeisanitarios.com',
                'country' => 'AR',
                'currency' => 'ARS',
            ], $overridesStore), 200),
        ]);
    }

    private function conectarYObtenerState(): string
    {
        $response = $this->get(route('configuracion.tiendanube.conectarRest'));
        $response->assertRedirect();

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);

        return $query['state'];
    }

    // ---- T008 ----

    public function test_conectar_rest_arma_la_url_de_autorizacion_sin_redirect_uri_en_la_query(): void
    {
        $response = $this->get(route('configuracion.tiendanube.conectarRest'));
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.tiendanube.com/apps/38015/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('redirect_uri', $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame($query['state'], session('tn_rest_oauth_state'));
    }

    // ---- T009 ----

    public function test_callback_rest_exitoso_deja_la_conexion_conectada_con_datos_de_tienda(): void
    {
        $this->fakeFlujoCompletoExitoso();
        $state = $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_rest_exito');

        $conexion = TiendanubeConexionRest::actual();
        $this->assertSame(EstadoConexion::Conectada, $conexion->estado);
        $this->assertSame('6922207', $conexion->store_id);
        $this->assertSame('read_products,write_products,read_orders', $conexion->scopes_otorgados);
        $this->assertSame('Pompei Sanitarios', $conexion->tienda_nombre);
        $this->assertSame('pompeisanitarios.com', $conexion->tienda_dominio);
        $this->assertNotNull($conexion->conectada_en);
        $this->assertSame('token-rest-de-prueba', $conexion->access_token);

        $crudo = \DB::table('tn_conexion_rest')->first();
        $this->assertStringNotContainsString('token-rest-de-prueba', (string) $crudo->access_token);

        $this->assertSame(1, \DB::table('tn_rest_operaciones_log')->where('operacion', 'conectar')->where('resultado', 'exito')->count());
        $this->assertSame(1, \DB::table('tn_rest_operaciones_log')->where('operacion', 'verificar')->where('resultado', 'exito')->count());
    }

    // ---- T010 ----

    public function test_callback_rest_con_state_invalido_no_deja_la_conexion_conectada(): void
    {
        $this->fakeFlujoCompletoExitoso();
        $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-de-prueba', 'state' => 'state-que-no-corresponde']));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_rest_error');
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }

    public function test_callback_rest_con_codigo_reusado_falla_en_el_segundo_intento(): void
    {
        $this->fakeFlujoCompletoExitoso();
        $state = $this->conectarYObtenerState();

        $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-de-prueba', 'state' => $state]))
            ->assertSessionHas('tn_rest_exito');

        $response = $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertSessionHas('tn_rest_error');
        // La conexión ya establecida en el primer intento sigue intacta.
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }

    // ---- T011 ----

    public function test_callback_rest_con_access_denied_rechaza_sin_mostrar_el_error_crudo(): void
    {
        $response = $this->get(route('configuracion.tiendanube.callbackRest', ['error' => 'access_denied']));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_rest_info');
        $this->assertStringNotContainsString('access_denied', (string) session('tn_rest_info'));
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }

    // ---- T012 ----

    public function test_verificacion_fr005_fallida_no_deja_conectada_aunque_el_token_se_haya_obtenido(): void
    {
        Http::fake([
            'www.tiendanube.com/apps/authorize/token' => Http::response([
                'access_token' => 'token-rest-de-prueba',
                'token_type' => 'bearer',
                'scope' => 'read_products',
                'user_id' => 6922207,
            ], 200),
            'api.tiendanube.com/v1/*/store' => Http::response(['message' => 'invalid_token'], 401),
        ]);

        $state = $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertSessionHas('tn_rest_error');
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
        $this->assertNull(TiendanubeConexionRest::actual()->getRawOriginal('access_token'));
    }

    // ---- T013 ----

    public function test_un_usuario_sin_permiso_recibe_403_en_conectar_rest_y_callback_rest(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('configuracion.tiendanube.conectarRest'))->assertStatus(403);
        $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'x', 'state' => 'y']))->assertStatus(403);
    }

    // ---- T020 ----

    public function test_estado_rest_devuelve_conexion_null_cuando_no_hay_conexion(): void
    {
        $response = $this->getJson(route('configuracion.tiendanube.estadoRest'));

        $response->assertOk()->assertJson(['ok' => true, 'estado' => 'no_configurada', 'conexion' => null]);
    }

    public function test_estado_rest_devuelve_los_campos_correctos_cuando_esta_conectada(): void
    {
        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente',
            'store_id' => '6922207',
            'scopes_otorgados' => 'read_products',
            'tienda_nombre' => 'Pompei Sanitarios',
            'tienda_dominio' => 'pompeisanitarios.com',
            'conectada_en' => now(),
            'estado' => EstadoConexion::Conectada,
        ]);

        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response([
            'name' => ['es' => 'Pompei Sanitarios'],
            'original_domain' => 'pompeisanitarios.com',
        ], 200)]);

        $response = $this->getJson(route('configuracion.tiendanube.estadoRest'));

        $response->assertOk();
        $response->assertJsonPath('estado', 'conectada');
        $response->assertJsonPath('conexion.tienda_nombre', 'Pompei Sanitarios');
        $response->assertJsonPath('conexion.tienda_dominio', 'pompeisanitarios.com');
        $response->assertJsonPath('conexion.scopes_otorgados', 'read_products');
        $response->assertJsonMissingPath('conexion.access_token');
    }

    // ---- T021 ----

    public function test_desconectar_rest_limpia_los_campos_y_registra_en_el_historial(): void
    {
        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente',
            'store_id' => '6922207',
            'scopes_otorgados' => 'read_products',
            'tienda_nombre' => 'Pompei Sanitarios',
            'tienda_dominio' => 'pompeisanitarios.com',
            'conectada_en' => now(),
            'estado' => EstadoConexion::Conectada,
        ]);

        $response = $this->postJson(route('configuracion.tiendanube.desconectarRest'));

        $response->assertOk()->assertJson(['ok' => true]);

        $conexion = TiendanubeConexionRest::actual();
        $this->assertSame(EstadoConexion::NoConfigurada, $conexion->estado);
        $this->assertNull($conexion->getRawOriginal('access_token'));
        $this->assertNull($conexion->store_id);
        $this->assertNull($conexion->scopes_otorgados);
        $this->assertNull($conexion->tienda_nombre);
        $this->assertNull($conexion->tienda_dominio);
        $this->assertNull($conexion->conectada_en);

        $this->assertSame(1, \DB::table('tn_rest_operaciones_log')->where('operacion', 'desconectar')->where('resultado', 'exito')->count());
    }

    // ---- T022 ----

    public function test_ningun_endpoint_expone_access_token_ni_client_secret(): void
    {
        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-secreto-vigente',
            'store_id' => '6922207',
            'estado' => EstadoConexion::Conectada,
            'conectada_en' => now(),
        ]);

        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response(['message' => 'invalid_token'], 401)]);

        $respuestaEstado = $this->getJson(route('configuracion.tiendanube.estadoRest'));
        $this->assertStringNotContainsString('token-secreto-vigente', $respuestaEstado->getContent());
        $this->assertStringNotContainsString('client-secret-de-prueba', $respuestaEstado->getContent());

        // Callback con error de verificación: el mensaje de error tampoco expone credenciales.
        Http::fake([
            'www.tiendanube.com/apps/authorize/token' => Http::response(['access_token' => 'token-callback-secreto', 'user_id' => 1, 'scope' => 'x'], 200),
            'api.tiendanube.com/v1/*/store' => Http::response(['message' => 'invalid_token'], 401),
        ]);
        $state = $this->conectarYObtenerState();
        $respuestaCallback = $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'c', 'state' => $state]));
        $mensajeFlash = (string) session('tn_rest_error');
        $this->assertStringNotContainsString('token-callback-secreto', $mensajeFlash);
        $this->assertStringNotContainsString('client-secret-de-prueba', $mensajeFlash);
    }
}
