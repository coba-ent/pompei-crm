<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Rol;
use App\Models\User;
use App\Services\Tiendanube\ClienteTiendanube;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 019): panel de estado (no configurada/conectada/caída, FR-006),
 * desconexión (conserva client_id/client_secret, FR-007) y superficie sin
 * secretos expuestos (FR-005/SC-003).
 */
class TiendanubeConexionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
    }

    public function test_la_pantalla_de_configuracion_renderiza(): void
    {
        $this->get(route('configuracion.tiendanube.index'))->assertOk();
    }

    public function test_un_usuario_sin_permiso_recibe_403(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('configuracion.tiendanube.index'))->assertStatus(403);
    }

    public function test_sin_conectar_el_estado_es_no_configurada(): void
    {
        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('estado', EstadoConexion::NoConfigurada->value)
            ->assertJsonPath('configuracion', null);
    }

    public function test_estado_conectada_devuelve_scopes_productos_y_fecha(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'token-vigente', 'scopes_otorgados' => 'read_products write_products',
            'productos_total' => 42, 'conectada_en' => now(), 'estado' => EstadoConexion::Conectada,
            'token_expira_en' => now()->addDays(200),
        ]);

        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('estado', 'conectada')
            ->assertJsonPath('configuracion.productos_total', 42)
            ->assertJsonPath('configuracion.scopes_otorgados', 'read_products write_products')
            ->assertJsonPath('configuracion.modo_solo_lectura', false)
            ->assertJsonPath('configuracion.dias_restantes', 200);
    }

    public function test_estado_conectada_con_token_por_vencer_devuelve_dias_restantes_bajo(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
            'token_expira_en' => now()->addDays(3),
        ]);

        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()->assertJsonPath('configuracion.dias_restantes', 3);
    }

    public function test_estado_caida_incluye_ultimo_error(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Caida,
            'ultimo_error' => 'La credencial fue rechazada por Tiendanube. Volvé a conectar.',
        ]);

        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('estado', 'caida')
            ->assertJsonPath('ultimo_error', 'La credencial fue rechazada por Tiendanube. Volvé a conectar.');
    }

    public function test_desconectar_borra_el_token_conserva_client_id_secret_y_el_historial(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-conservado', 'client_secret' => 'client-secret-conservado',
            'access_token' => 'token-vigente', 'scopes_otorgados' => 'read_products',
            'productos_total' => 10, 'conectada_en' => now(), 'estado' => EstadoConexion::Conectada,
            'token_expira_en' => now()->addDays(300),
        ]);

        $response = $this->postJson(route('configuracion.tiendanube.desconectar'));

        $response->assertOk()->assertJsonPath('ok', true);

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertNull($configuracion->access_token);
        $this->assertNull($configuracion->token_expira_en);
        $this->assertSame(EstadoConexion::NoConfigurada, $configuracion->estado);
        $this->assertSame('client-id-conservado', $configuracion->client_id);
        $this->assertSame('client-secret-conservado', $configuracion->client_secret);

        $this->assertTrue(TiendanubeOperacionLog::where('operacion', 'desconectar')->exists());
    }

    public function test_tras_desconectar_el_estado_vuelve_a_no_configurada(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);

        $this->postJson(route('configuracion.tiendanube.desconectar'))->assertOk();

        $response = $this->getJson(route('configuracion.tiendanube.estado'));

        $response->assertOk()
            ->assertJsonPath('estado', EstadoConexion::NoConfigurada->value)
            ->assertJsonPath('configuracion', null);
    }

    public function test_reconexion_tras_desconectar_no_dispara_un_nuevo_registro(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-conservado', 'client_secret' => 'client-secret-conservado',
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);

        $this->postJson(route('configuracion.tiendanube.desconectar'))->assertOk();

        Http::fake([
            'admin-mcp.tiendanube.com/token' => Http::response(['access_token' => 'token-nuevo', 'expires_in' => 31536000, 'scope' => 'read_products'], 200),
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => ['pagination' => ['total_elements' => 5], 'products' => []]],
            ], 200),
        ]);

        $this->get(route('configuracion.tiendanube.conectar'));

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/register'));
        $this->assertSame('client-id-conservado', TiendanubeConfiguracion::actual()->client_id);
    }

    public function test_ningun_endpoint_de_la_superficie_expone_secretos(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret-de-superficie',
            'access_token' => 'token-secreto-de-superficie', 'estado' => EstadoConexion::Conectada,
        ]);

        $endpoints = [
            fn () => $this->getJson(route('configuracion.tiendanube.estado')),
            fn () => $this->getJson(route('configuracion.tiendanube.historial')),
        ];

        foreach ($endpoints as $llamar) {
            $contenido = $llamar()->getContent();
            $this->assertStringNotContainsString('token-secreto-de-superficie', $contenido);
            $this->assertStringNotContainsString('client-secret-de-superficie', $contenido);
            $this->assertStringNotContainsString('access_token', $contenido);
            $this->assertStringNotContainsString('client_secret', $contenido);
        }
    }

    public function test_ningun_dato_sensible_aparece_en_el_historial_tras_una_operacion(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
        ]);

        Http::fake(['admin-mcp.tiendanube.com/' => Http::response(['message' => 'invalid_token'], 401)]);

        app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertNotNull($registro);
        $this->assertStringNotContainsString('token-vigente-de-prueba', (string) $registro->mensaje_error);

        $contenido = $this->getJson(route('configuracion.tiendanube.historial'))->getContent();
        $this->assertStringNotContainsString('token-vigente-de-prueba', $contenido);
    }
}
