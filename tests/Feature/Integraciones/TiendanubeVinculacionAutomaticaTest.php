<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\Tiendanube\Excepciones\VinculacionAutomaticaFallidaException;
use App\Services\Tiendanube\VinculadorAutomatico;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 024, US1: vinculación automática de Tiendanube resolviendo el SKU
 * vigente contra el catálogo REST en vivo (`GET /products`, paginado), en vez
 * de `tn_orden_items`/Excel. FR-006..FR-013 (spec.md).
 */
class TiendanubeVinculacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk',
            'store_id' => '999',
            'estado' => EstadoConexion::Conectada->value,
        ]);
    }

    /**
     * Simula `GET /{store_id}/products` con una única página de productos.
     *
     * @param  array<int, array{id: int, status?: string, variants: array<int, array{id: int, sku: ?string}>}>  $productos
     */
    private function fakeCatalogo(array $productos): void
    {
        Http::fake([
            'api.tiendanube.com/v1/*/products*' => function (ClientRequest $request) use ($productos) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
                $pagina = (int) ($params['page'] ?? 1);

                return Http::response($pagina === 1 ? $productos : []);
            },
        ]);
    }

    public function test_variante_sin_ninguna_orden_sincronizada_se_vincula_por_sku_del_catalogo_en_vivo(): void
    {
        $producto = Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '9010']]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(0, $resumen['fallidas']);
        $this->assertDatabaseHas('tn_variante_producto', [
            'variant_id' => 555, 'tn_product_id' => 111, 'producto_id' => $producto->id,
        ]);
        $this->assertDatabaseCount('tn_orden_items', 0);
    }

    public function test_sku_sin_match_deja_pendiente_con_motivo(): void
    {
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '999999']]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('555', $resumen['detalle_fallidas'][0]['referencia']);
        $this->assertSame('producto_no_encontrado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertDatabaseCount('tn_variante_producto', 0);
    }

    /**
     * Varios SKU reales de la tienda traen el id seguido de texto libre (ej.
     * "26168 SKU 7024 ABAB-9006C", "41036 CAJ303060") — sólo el número antes
     * del primer espacio corresponde al id del producto del CRM.
     */
    public function test_sku_con_texto_despues_del_id_se_vincula_por_el_numero_inicial(): void
    {
        $producto = Producto::factory()->create(['id' => 26168]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '26168 SKU 7024 ABAB-9006C']]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 555, 'producto_id' => $producto->id]);
    }

    public function test_variante_sin_sku_tiene_motivo_sin_sku(): void
    {
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => null]]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('sin_sku', $resumen['detalle_fallidas'][0]['motivo']);
    }

    public function test_dos_variantes_con_el_mismo_sku_solo_la_primera_se_vincula(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [
                ['id' => 555, 'sku' => '9010'],
                ['id' => 556, 'sku' => '9010'],
            ]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertDatabaseCount('tn_variante_producto', 1);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertSame('producto', $resumen['detalle_fallidas'][0]['detalle']);
    }

    public function test_producto_con_multiples_variantes_evalua_cada_una_por_separado(): void
    {
        Producto::factory()->create(['id' => 9010]);
        Producto::factory()->create(['id' => 9011]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [
                ['id' => 555, 'sku' => '9010'],
                ['id' => 556, 'sku' => '9011'],
            ]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(2, $resumen['total']);
        $this->assertSame(2, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 555, 'producto_id' => 9010]);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 556, 'producto_id' => 9011]);
    }

    public function test_producto_cerrado_excluido_y_pausado_incluido(): void
    {
        Producto::factory()->create(['id' => 9010]);
        Producto::factory()->create(['id' => 9011]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'closed', 'variants' => [['id' => 555, 'sku' => '9010']]],
            ['id' => 112, 'status' => 'paused', 'variants' => [['id' => 556, 'sku' => '9011']]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 556, 'producto_id' => 9011]);
        $this->assertDatabaseMissing('tn_variante_producto', ['variant_id' => 555]);
    }

    public function test_producto_inactivo_del_crm_se_vincula_igual(): void
    {
        $producto = Producto::factory()->create(['id' => 9010, 'activo' => false]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '9010']]],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 555, 'producto_id' => $producto->id]);
    }

    public function test_reintentar_la_corrida_no_modifica_lo_ya_vinculado(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo([
            ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '9010']]],
        ]);

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        $vinculo = TiendanubeVarianteProducto::where('variant_id', 555)->firstOrFail();

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['total']);
        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertDatabaseCount('tn_variante_producto', 1);
        $this->assertDatabaseHas('tn_variante_producto', ['id' => $vinculo->id, 'variant_id' => 555]);
    }

    public function test_recorrido_de_dos_paginas_se_agota_completo_antes_de_procesar(): void
    {
        Producto::factory()->create(['id' => 9010]);
        Producto::factory()->create(['id' => 9011]);

        // La primera página viene llena (50 productos) para forzar que el recorrido pida la segunda
        // página antes de cortar (research.md R1: corta con "menos de per_page resultados").
        $paginaLlena = array_map(
            fn (int $i) => ['id' => 1000 + $i, 'status' => 'published', 'variants' => [['id' => 9000 + $i, 'sku' => null]]],
            range(1, 49)
        );
        $paginaLlena[] = ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '9010']]];

        Http::fake([
            'api.tiendanube.com/v1/*/products*' => Http::sequence()
                ->push($paginaLlena)
                ->push([['id' => 112, 'status' => 'published', 'variants' => [['id' => 556, 'sku' => '9011']]]])
                ->push([]),
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(51, $resumen['total']);
        $this->assertSame(2, $resumen['vinculadas']);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 555, 'producto_id' => 9010]);
        $this->assertDatabaseHas('tn_variante_producto', ['variant_id' => 556, 'producto_id' => 9011]);
    }

    public function test_si_el_catalogo_falla_a_mitad_de_camino_se_aborta_sin_crear_ningun_vinculo(): void
    {
        Producto::factory()->create(['id' => 9010]);

        Http::fake([
            'api.tiendanube.com/v1/*/products*' => Http::sequence()
                ->push(array_fill(0, 50, ['id' => 111, 'status' => 'published', 'variants' => [['id' => 555, 'sku' => '9010']]]))
                ->push(['message' => 'Bad request'], 400),
        ]);

        $this->expectException(VinculacionAutomaticaFallidaException::class);

        try {
            app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        } finally {
            $this->assertDatabaseCount('tn_variante_producto', 0);
        }
    }
}
