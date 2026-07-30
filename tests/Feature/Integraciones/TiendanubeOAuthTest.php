<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1 (spec 019): auto-registro OAuth (RFC 7591), armado de la URL de
 * autorización con PKCE, y callback (intercambio + verificación FR-003a).
 * Todo con Http::fake() — ningún test llama de verdad a
 * admin-mcp.tiendanube.com (spec.md, restricción crítica; research.md §R11).
 */
class TiendanubeOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private const SCOPES_ESPERADOS = [
        'read_products', 'write_products', 'read_orders', 'write_orders',
        'read_customers', 'write_customers', 'read_content', 'write_content',
        'read_coupons', 'write_coupons', 'write_scripts', 'write_shipping',
    ];

    private function fakeFlujoCompletoExitoso(int $totalProductos = 101): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/register' => Http::response([
                'client_id' => 'client-id-generado',
                'client_secret' => 'client-secret-generado',
                'client_id_issued_at' => now()->timestamp,
            ], 200),
            'admin-mcp.tiendanube.com/token' => Http::response([
                'access_token' => 'token-oauth-de-prueba',
                'token_type' => 'Bearer',
                'expires_in' => 31536000,
                'scope' => implode(' ', self::SCOPES_ESPERADOS),
            ], 200),
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'isError' => false,
                    'content' => [],
                    'structuredContent' => ['pagination' => ['total_elements' => $totalProductos], 'products' => []],
                ],
            ], 200),
        ]);
    }

    private function conectarYObtenerState(): string
    {
        $response = $this->get(route('configuracion.tiendanube.conectar'));
        $response->assertRedirect();

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);

        return $query['state'];
    }

    public function test_la_primera_conexion_auto_registra_el_cliente_oauth(): void
    {
        $this->fakeFlujoCompletoExitoso();

        $this->get(route('configuracion.tiendanube.conectar'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/register'));

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame('client-id-generado', $configuracion->client_id);
        $this->assertNotNull($configuracion->getRawOriginal('client_secret'));
        $this->assertSame('client-secret-generado', $configuracion->client_secret);
    }

    public function test_una_segunda_conexion_reutiliza_el_client_id_ya_guardado(): void
    {
        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-ya-guardado',
            'client_secret' => 'client-secret-ya-guardado',
        ]);

        $this->fakeFlujoCompletoExitoso();

        $this->get(route('configuracion.tiendanube.conectar'));

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/register'));
        $this->assertSame('client-id-ya-guardado', TiendanubeConfiguracion::actual()->client_id);
    }

    public function test_conectar_arma_la_url_de_autorizacion_con_pkce_y_todos_los_scopes(): void
    {
        $this->fakeFlujoCompletoExitoso();

        $response = $this->get(route('configuracion.tiendanube.conectar'));
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://admin-mcp.tiendanube.com/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id-generado', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);

        foreach (self::SCOPES_ESPERADOS as $scope) {
            $this->assertStringContainsString($scope, $query['scope']);
        }

        $this->assertSame($query['state'], session('tn_oauth_state'));
        $this->assertNotEmpty(session('tn_oauth_code_verifier'));
    }

    public function test_callback_exitoso_deja_la_conexion_conectada_con_productos_total(): void
    {
        $this->fakeFlujoCompletoExitoso(totalProductos: 250);
        $state = $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_exito');

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame(EstadoConexion::Conectada, $configuracion->estado);
        $this->assertSame(250, $configuracion->productos_total);
        $this->assertNotNull($configuracion->conectada_en);
        $this->assertSame('token-oauth-de-prueba', $configuracion->access_token);
        $this->assertNotNull($configuracion->token_expira_en);
        $this->assertEqualsWithDelta(now()->addSeconds(31536000)->timestamp, $configuracion->token_expira_en->timestamp, 5);

        $crudo = \DB::table('tn_configuracion')->first();
        $this->assertStringNotContainsString('token-oauth-de-prueba', (string) $crudo->access_token);
    }

    public function test_callback_con_state_invalido_no_deja_la_conexion_conectada(): void
    {
        $this->fakeFlujoCompletoExitoso();
        $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => 'state-que-no-corresponde']));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_error');
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_callback_con_codigo_reusado_falla_en_el_segundo_intento(): void
    {
        $this->fakeFlujoCompletoExitoso();
        $state = $this->conectarYObtenerState();

        $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => $state]))
            ->assertSessionHas('tn_exito');

        $response = $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertSessionHas('tn_error');
        // La conexión ya establecida en el primer intento sigue intacta.
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_verificacion_fr003a_fallida_por_error_http_no_deja_conectada(): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/register' => Http::response(['client_id' => 'client-id-generado', 'client_secret' => 'client-secret-generado'], 200),
            'admin-mcp.tiendanube.com/token' => Http::response(['access_token' => 'token-oauth-de-prueba', 'token_type' => 'Bearer', 'expires_in' => 31536000, 'scope' => 'read_products'], 200),
            'admin-mcp.tiendanube.com/' => Http::response(['message' => 'invalid_token'], 401),
        ]);

        $state = $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertSessionHas('tn_error');
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_verificacion_fr003a_fallida_por_iserror_no_deja_conectada(): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/register' => Http::response(['client_id' => 'client-id-generado', 'client_secret' => 'client-secret-generado'], 200),
            'admin-mcp.tiendanube.com/token' => Http::response(['access_token' => 'token-oauth-de-prueba', 'token_type' => 'Bearer', 'expires_in' => 31536000, 'scope' => 'read_products'], 200),
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Argumento inválido.']]],
            ], 200),
        ]);

        $state = $this->conectarYObtenerState();

        $response = $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-prueba', 'state' => $state]));

        $response->assertSessionHas('tn_error');
        $this->assertNotSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_el_auto_registro_fallido_informa_el_error_sin_redirigir_a_tiendanube(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/register' => Http::response(['error' => 'server_error'], 500)]);

        $response = $this->get(route('configuracion.tiendanube.conectar'));

        $response->assertRedirect(route('configuracion.tiendanube.index'));
        $response->assertSessionHas('tn_error');
        $this->assertNull(TiendanubeConfiguracion::actual()->client_id);
    }

    public function test_un_usuario_sin_permiso_recibe_403_en_conectar_y_callback(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('configuracion.tiendanube.conectar'))->assertStatus(403);
        $this->get(route('configuracion.tiendanube.callback', ['code' => 'x', 'state' => 'y']))->assertStatus(403);
    }
}
