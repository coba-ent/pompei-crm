<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T014/T023/T031 (spec 022): el requisito central de esta spec es que la
 * conexión Application REST no toque `tn_configuracion`/`tn_operaciones_log`
 * (conexión MCP, spec 019) bajo ningún escenario, y viceversa (FR-008,
 * FR-012, FR-013). Todo con Http::fake() — nunca contra la cuenta real.
 */
class TiendanubeConexionRestAislamientoTest extends TestCase
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

    // ---- T014 ----

    public function test_conectar_rest_exitoso_no_modifica_tn_configuracion_ni_llama_al_mcp(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-mcp-existente',
            'client_secret' => 'client-secret-mcp-existente',
            'access_token' => 'token-mcp-existente',
            'estado' => EstadoConexion::Conectada,
        ]);
        $estadoMcpAntes = TiendanubeConfiguracion::actual()->toArray();

        Http::fake([
            'www.tiendanube.com/apps/authorize/token' => Http::response([
                'access_token' => 'token-rest-de-prueba', 'user_id' => 6922207, 'scope' => 'read_products',
            ], 200),
            'api.tiendanube.com/v1/*/store' => Http::response([
                'name' => ['es' => 'Pompei Sanitarios'], 'original_domain' => 'pompeisanitarios.com',
            ], 200),
        ]);

        $response = $this->get(route('configuracion.tiendanube.conectarRest'));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('configuracion.tiendanube.callbackRest', ['code' => 'codigo-rest', 'state' => $query['state']]))
            ->assertSessionHas('tn_rest_exito');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'admin-mcp.tiendanube.com'));

        $this->assertSame($estadoMcpAntes, TiendanubeConfiguracion::actual()->toArray());
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }

    // ---- T023 ----

    public function test_desconectar_rest_no_modifica_tn_configuracion_ni_su_historial(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-mcp', 'client_secret' => 'client-secret-mcp',
            'access_token' => 'token-mcp-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        $estadoMcpAntes = TiendanubeConfiguracion::actual()->toArray();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-rest-vigente', 'store_id' => '6922207', 'estado' => EstadoConexion::Conectada,
        ]);

        $this->postJson(route('configuracion.tiendanube.desconectarRest'))->assertOk();

        $this->assertSame($estadoMcpAntes, TiendanubeConfiguracion::actual()->toArray());
        $this->assertSame(0, \DB::table('tn_operaciones_log')->count());
        $this->assertSame(EstadoConexion::NoConfigurada, TiendanubeConexionRest::actual()->estado);
    }

    public function test_desconectar_mcp_no_modifica_tn_conexion_rest_ni_su_historial(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-mcp', 'client_secret' => 'client-secret-mcp',
            'access_token' => 'token-mcp-vigente', 'estado' => EstadoConexion::Conectada,
        ]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-rest-vigente', 'store_id' => '6922207',
            'tienda_nombre' => 'Pompei Sanitarios', 'estado' => EstadoConexion::Conectada, 'conectada_en' => now(),
        ]);
        $estadoRestAntes = TiendanubeConexionRest::actual()->toArray();

        $this->postJson(route('configuracion.tiendanube.desconectar'))->assertOk();

        $this->assertSame($estadoRestAntes, TiendanubeConexionRest::actual()->toArray());
        $this->assertSame(0, \DB::table('tn_rest_operaciones_log')->count());
        $this->assertSame(EstadoConexion::NoConfigurada, TiendanubeConfiguracion::actual()->estado);
    }

    // ---- T031 ----

    public function test_una_conexion_rest_caida_no_cambia_el_estado_de_la_conexion_mcp(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-mcp', 'client_secret' => 'client-secret-mcp',
            'access_token' => 'token-mcp-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        $estadoMcpAntes = TiendanubeConfiguracion::actual()->toArray();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-rest-vigente', 'store_id' => '6922207', 'estado' => EstadoConexion::Conectada,
        ]);

        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response(['message' => 'invalid_token'], 401)]);

        $this->getJson(route('configuracion.tiendanube.estadoRest'))->assertOk();

        $this->assertSame(EstadoConexion::Caida, TiendanubeConexionRest::actual()->estado);
        $this->assertSame($estadoMcpAntes, TiendanubeConfiguracion::actual()->toArray());
    }

    public function test_una_conexion_mcp_caida_simulada_no_cambia_el_estado_de_la_conexion_rest(): void
    {
        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-rest-vigente', 'store_id' => '6922207', 'estado' => EstadoConexion::Conectada,
        ]);
        $estadoRestAntes = TiendanubeConexionRest::actual()->toArray();

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-mcp', 'client_secret' => 'client-secret-mcp',
            'access_token' => 'token-mcp-vigente', 'estado' => EstadoConexion::Conectada,
        ]);

        Http::fake(['admin-mcp.tiendanube.com/' => Http::response(['message' => 'invalid_token'], 401)]);

        app(\App\Services\Tiendanube\ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
        $this->assertSame($estadoRestAntes, TiendanubeConexionRest::actual()->toArray());
    }
}
