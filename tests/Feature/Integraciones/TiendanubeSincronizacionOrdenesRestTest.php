<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Rol;
use App\Models\User;
use App\Services\Tiendanube\SincronizadorOrdenes;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 024, US2: sincronización de órdenes de Tiendanube vía el cliente REST
 * (`GET /orders`, contracts/api-tiendanube-rest.md §3), reemplazando a
 * `ClienteTiendanube` (MCP). FR-013 (sin duplicar), FR-012a/SC-010 (exclusión
 * total de storefront=meli), cortes de FR-017/FR-018, mismo comportamiento
 * observable que la versión MCP (spec 017).
 */
class TiendanubeSincronizacionOrdenesRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false, 'dias_primera_sync' => 30,
        ]);
    }

    private function ordenCruda(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'status' => 'open',
            'payment_status' => 'paid',
            'shipping_status' => 'unpacked',
            'completed_at' => now()->toIso8601String(),
            'total' => 500.0,
            'currency' => 'ARS',
            'storefront' => 'store',
            'contact_email' => "comprador{$id}@test.com", 'contact_name' => 'Comprador', 'contact_identification' => '',
            'products' => [[
                'product_id' => 10, 'variant_id' => 20 + $id, 'name' => 'Producto X',
                'variant_values' => [], 'quantity' => 1, 'price' => 500.0,
            ]],
        ], $overrides);
    }

    private function fakearListado(array $ordenes): void
    {
        Http::fake([
            'api.tiendanube.com/v1/*/orders*' => function (ClientRequest $request) use ($ordenes) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
                $pagina = (int) ($params['page'] ?? 1);

                return Http::response($pagina === 1 ? $ordenes : []);
            },
        ]);
    }

    public function test_sincronizacion_trae_ordenes_nuevas_sin_usar_el_mcp(): void
    {
        $this->fakearListado([$this->ordenCruda(1001), $this->ordenCruda(1002)]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(2, $resultado['nuevas']);
        $this->assertDatabaseCount('tn_ordenes', 2);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'api.tiendanube.com'));
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

    public function test_paginado_de_dos_paginas_se_recorre_completo(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/*/orders*' => Http::sequence()
                ->push(array_fill(0, 50, $this->ordenCruda(3000)))
                ->push([$this->ordenCruda(3001)])
                ->push([]),
        ]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertDatabaseHas('tn_ordenes', ['tn_order_id' => 3001]);
    }

    public function test_bloquea_si_la_funcion_esta_desactivada(): void
    {
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseHas('tn_rest_operaciones_log', ['operacion' => 'sincronizar_ordenes', 'resultado' => 'bloqueada']);
    }

    public function test_bloquea_si_el_modo_solo_lectura_esta_activo(): void
    {
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('sólo lectura', $resultado['mensaje']);
    }

    public function test_bloquea_si_la_conexion_no_esta_establecida(): void
    {
        TiendanubeConexionRest::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);

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
