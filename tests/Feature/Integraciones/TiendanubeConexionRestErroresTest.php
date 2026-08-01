<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Rol;
use App\Services\Tiendanube\VerificadorConexionRest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 022): manejo de errores de la verificación contra la REST API
 * (VerificadorConexionRest, contracts/api-tiendanube-rest.md §5) — 401/404
 * marca la conexión como caída sin reintento; 429/5xx reintenta con espera
 * creciente y NO marca la conexión como caída (research.md R5).
 */
class TiendanubeConexionRestErroresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente-de-prueba',
            'store_id' => '6922207',
            'estado' => EstadoConexion::Conectada,
        ]);
    }

    // ---- T029 ----

    public function test_401_marca_caida_con_ultimo_error_legible_y_no_reintenta(): void
    {
        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response(['message' => 'invalid_token'], 401)]);

        $respuesta = app(VerificadorConexionRest::class)->verificar('token-vigente-de-prueba', '6922207');

        $this->assertFalse($respuesta['ok']);
        Http::assertSentCount(1); // sin reintento

        $this->getJson(route('configuracion.tiendanube.estadoRest'))->assertOk();

        $conexion = TiendanubeConexionRest::actual();
        $this->assertSame(EstadoConexion::Caida, $conexion->estado);
        $this->assertNotNull($conexion->ultimo_error);
        $this->assertStringNotContainsString('invalid_token', $conexion->ultimo_error);
        $this->assertStringNotContainsString('Exception', $conexion->ultimo_error);

        $this->assertSame(1, \DB::table('tn_rest_operaciones_log')->where('operacion', 'verificar')->where('resultado', 'error')->count());
    }

    public function test_404_recibe_el_mismo_tratamiento_que_401(): void
    {
        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response(['message' => 'not_found'], 404)]);

        $this->getJson(route('configuracion.tiendanube.estadoRest'))->assertOk();

        $this->assertSame(EstadoConexion::Caida, TiendanubeConexionRest::actual()->estado);
    }

    // ---- T030 ----

    public function test_429_aplica_espera_creciente_y_reintento_acotado_sin_marcar_caida(): void
    {
        Http::fake(['api.tiendanube.com/v1/*/store' => Http::response(['message' => 'rate_limited'], 429)]);

        $respuesta = app(VerificadorConexionRest::class)->verificar('token-vigente-de-prueba', '6922207');

        $this->assertFalse($respuesta['ok']);
        Http::assertSentCount(4); // intento original + 3 reintentos

        $this->getJson(route('configuracion.tiendanube.estadoRest'))->assertOk();
        $this->assertSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }

    public function test_5xx_reintenta_sin_marcar_la_conexion_como_caida(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/*/store' => Http::sequence()
                ->push(['message' => 'internal'], 500)
                ->push(['message' => 'internal'], 500)
                ->push(['name' => ['es' => 'Pompei Sanitarios'], 'original_domain' => 'pompeisanitarios.com'], 200),
        ]);

        $respuesta = app(VerificadorConexionRest::class)->verificar('token-vigente-de-prueba', '6922207');

        $this->assertTrue($respuesta['ok']);
        Http::assertSentCount(3);

        $this->assertSame(EstadoConexion::Conectada, TiendanubeConexionRest::actual()->estado);
    }
}
