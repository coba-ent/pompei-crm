<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Rol;
use App\Models\User;
use App\Services\Tiendanube\SincronizadorOrdenes;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1 (spec 017): sincronización de órdenes de Tiendanube. FR-013 (sin
 * duplicar), FR-012a/SC-010 (exclusión total de storefront=meli), cortes de
 * FR-017/FR-018 y acceso denegado sin permiso (FR-003).
 */
class TiendanubeSincronizacionTest extends TestCase
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
            'modo_solo_lectura' => false,
        ]);
    }

    private function ordenCruda(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'status' => 'open',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unpacked',
            'completed_at' => now()->toIso8601String(),
            'total' => ['amount' => 500.0, 'currency' => 'ARS'],
            'storefront' => 'store',
            'customer' => ['id' => 900 + $id, 'email' => "comprador{$id}@test.com", 'name' => 'Comprador', 'cpf_cnpj' => null],
            'items' => [[
                'product_id' => 10, 'variant_id' => 20 + $id, 'name' => 'Producto X',
                'variant_values' => [], 'quantity' => 1, 'price' => ['amount' => 500.0, 'currency' => 'ARS'],
            ]],
        ], $overrides);
    }

    private function fakearListado(array $ordenes): void
    {
        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => ['orders' => $ordenes]],
            ], 200),
        ]);
    }

    public function test_la_pantalla_del_listado_renderiza(): void
    {
        $this->get(route('ingresos.tiendanube.index'))->assertOk();
    }

    public function test_sincronizacion_trae_ordenes_nuevas(): void
    {
        $this->fakearListado([$this->ordenCruda(1001), $this->ordenCruda(1002)]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $resultado['nuevas']);
        $this->assertDatabaseCount('tn_ordenes', 2);
    }

    public function test_resincronizar_no_duplica(): void
    {
        $this->fakearListado([$this->ordenCruda(1001)]);

        app(SincronizadorOrdenes::class)->ejecutar();
        $segunda = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($segunda['ok']);
        $this->assertDatabaseCount('tn_ordenes', 1);
    }

    /** FR-012a/SC-010: una orden storefront=meli nunca aparece, ni siquiera si la trae la consulta. */
    public function test_orden_storefront_meli_nunca_se_persiste(): void
    {
        $this->fakearListado([
            $this->ordenCruda(2001, ['storefront' => 'meli']),
            $this->ordenCruda(2002, ['storefront' => 'store']),
        ]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['nuevas']);
        $this->assertDatabaseCount('tn_ordenes', 1);
        $this->assertDatabaseMissing('tn_ordenes', ['tn_order_id' => 2001]);
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 2002]);
    }

    public function test_bloquea_si_la_funcion_esta_desactivada(): void
    {
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseHas('tn_operaciones_log', ['operacion' => 'sincronizar_ordenes', 'resultado' => 'bloqueada']);
    }

    public function test_bloquea_si_el_modo_solo_lectura_esta_activo(): void
    {
        TiendanubeConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('sólo lectura', $resultado['mensaje']);
    }

    public function test_bloquea_si_la_conexion_no_esta_establecida(): void
    {
        TiendanubeConfiguracion::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('reconectar', $resultado['mensaje']);
    }

    public function test_acceso_denegado_sin_permiso_ventas_ver(): void
    {
        $sinPermiso = User::factory()->create();
        $this->actingAs($sinPermiso);

        $this->getJson(route('ingresos.tiendanube.datatable'))->assertForbidden();
    }
}
