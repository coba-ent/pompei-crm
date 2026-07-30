<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use App\Services\Tiendanube\ClienteTiendanube;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * US4 (spec 019): manejo de errores del servidor MCP, implementado en un
 * único punto en ClienteTiendanube (research.md §R6, contracts/admin-mcp-
 * tiendanube.md §6). Sin renovación de token (a diferencia de Mercado
 * Libre): 401 marca "Caída" directamente, sin ningún reintento — la única
 * salida es rehacer el flujo de conexión completo (no hay refresh_token).
 */
class TiendanubeManejoErroresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    public function test_401_marca_caida_con_ultimo_error_legible_y_no_reintenta(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response(['message' => 'invalid_token'], 401)]);

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(1); // sin reintento
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);

        $mensaje = TiendanubeConfiguracion::actual()->ultimo_error;
        $this->assertNotNull($mensaje);
        // Legible (SC-004): no es el cuerpo crudo de la respuesta HTTP ni una traza de excepción.
        $this->assertStringNotContainsString('invalid_token', $mensaje);
        $this->assertStringNotContainsString('Exception', $mensaje);
        $this->assertStringNotContainsString('{"message"', $mensaje);
    }

    public function test_tras_una_conexion_caida_la_unica_salida_es_rehacer_el_flujo_completo(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response(['message' => 'invalid_token'], 401)]);
        app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);

        // No existe ninguna acción de "recargar sólo el token" (a diferencia de
        // spec 015): las únicas rutas de recuperación son conectar/callback.
        $this->assertFalse(Route::has('configuracion.tiendanube.credenciales'));
        $this->assertFalse(Route::has('configuracion.tiendanube.probar'));
        $this->assertTrue(Route::has('configuracion.tiendanube.conectar'));
        $this->assertTrue(Route::has('configuracion.tiendanube.callback'));
    }

    public function test_recuperacion_completa_caida_reconectar_vuelve_a_conectada(): void
    {
        // Un único Http::fake(): llamar Http::fake() dos veces APILA stubs en vez de
        // reemplazarlos (el primero registrado sigue ganando) — se necesita una
        // secuencia sobre el mismo endpoint '/' para simular la caída y luego la
        // verificación exitosa de la reconexión.
        Http::fake([
            'admin-mcp.tiendanube.com/token' => Http::response(['access_token' => 'token-nuevo-valido', 'expires_in' => 31536000, 'scope' => 'read_products'], 200),
            'admin-mcp.tiendanube.com/' => Http::sequence()
                ->push(['message' => 'invalid_token'], 401)
                ->push([
                    'jsonrpc' => '2.0', 'id' => 1,
                    'result' => ['isError' => false, 'structuredContent' => ['pagination' => ['total_elements' => 7], 'products' => []]],
                ], 200),
        ]);

        app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);

        // Historia 4/edge case: la única salida es rehacer el flujo completo
        // (conectar → callback), reutilizando el cliente OAuth ya registrado.
        $response = $this->get(route('configuracion.tiendanube.conectar'));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/register'));

        $this->get(route('configuracion.tiendanube.callback', ['code' => 'codigo-de-recuperacion', 'state' => $query['state']]))
            ->assertSessionHas('tn_exito');

        $configuracion = TiendanubeConfiguracion::actual();
        $this->assertSame(EstadoConexion::Conectada, $configuracion->estado);
        $this->assertSame(7, $configuracion->productos_total);
        $this->assertSame('token-nuevo-valido', $configuracion->access_token);
    }

    public function test_429_aplica_espera_creciente_hasta_3_reintentos_sin_marcar_caida(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response(['message' => 'rate_limited'], 429)]);

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(4); // intento original + 3 reintentos
        // 429 no es un rechazo de credencial: no marca la conexión como caída.
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_5xx_reintenta_sin_marcar_la_conexion_como_caida(): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::sequence()
                ->push(['message' => 'internal'], 500)
                ->push(['message' => 'internal'], 500)
                ->push([
                    'jsonrpc' => '2.0', 'id' => 1,
                    'result' => ['isError' => false, 'structuredContent' => ['pagination' => ['total_elements' => 5], 'products' => []]],
                ], 200),
        ]);

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertTrue($respuesta->exito);
        Http::assertSentCount(3);
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
    }

    public function test_iserror_true_se_registra_como_error_sin_afectar_el_estado_de_la_conexion(): void
    {
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'El producto indicado no existe.']]],
        ], 200)]);

        $respuesta = app(ClienteTiendanube::class)->leer('get_product', ['product_id' => 999999]);

        $this->assertTrue($respuesta->fallo());
        Http::assertSentCount(1); // sin reintento: no es un error transitorio
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);
        $this->assertSame('El producto indicado no existe.', $respuesta->mensajeError);
    }

    public function test_credenciales_ilegibles_marca_caida_sin_llamar_al_servidor(): void
    {
        // Simula que el access_token cifrado quedó ilegible (APP_KEY cambió): se
        // escribe directo en la columna un valor que Crypt no puede descifrar.
        \DB::table('tn_configuracion')->where('id', TiendanubeConfiguracion::actual()->id)
            ->update(['access_token' => 'valor-no-cifrado-ilegible']);

        Http::fake();

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        $this->assertTrue($respuesta->fallo());
        Http::assertNothingSent();
        $this->assertSame(EstadoConexion::Caida, TiendanubeConfiguracion::actual()->estado);
    }
}
