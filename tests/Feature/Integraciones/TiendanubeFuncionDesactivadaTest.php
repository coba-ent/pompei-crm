<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Rol;
use App\Services\Tiendanube\ClienteTiendanube;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FR-006b (heredado de spec 015, sin cambios de intención — tasks.md T029):
 * con la función "Tiendanube" desactivada en Funciones Avanzadas, toda
 * operación (lectura y escritura) se rechaza y se registra como bloqueada,
 * sin alterar el estado de la conexión — re-verificado contra el
 * ClienteTiendanube basado en MCP.
 */
class TiendanubeFuncionDesactivadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        // La función queda desactivada (default del seeder): no se hace ->update(['activa' => true]).

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-de-prueba',
            'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    public function test_toda_operacion_se_rechaza_sin_alterar_el_estado_de_la_conexion(): void
    {
        Http::fake();

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1]);

        Http::assertNothingSent();
        $this->assertTrue($respuesta->fueBloqueada());
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConfiguracion::actual()->estado);

        $registro = TiendanubeOperacionLog::latest('id')->first();
        $this->assertSame('bloqueada', $registro->resultado);
    }

    public function test_la_verificacion_fr003a_del_callback_omite_el_guard(): void
    {
        // omitir_guard_funcion: true — permite verificar el token recién
        // obtenido dentro del propio callback OAuth, aunque la función
        // "Tiendanube" todavía no esté activa (FR-003a).
        Http::fake(['admin-mcp.tiendanube.com/' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['isError' => false, 'structuredContent' => ['pagination' => ['total_elements' => 3], 'products' => []]],
        ], 200)]);

        $respuesta = app(ClienteTiendanube::class)->leer('list_products', ['page' => 1, 'page_size' => 1], ['omitir_guard_funcion' => true]);

        $this->assertTrue($respuesta->exito);
        Http::assertSentCount(1);
    }
}
