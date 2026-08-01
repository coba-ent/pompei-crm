<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\MercadoLibre\Excepciones\VinculacionAutomaticaFallidaException;
use App\Services\MercadoLibre\VinculadorAutomatico;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 023 (reemplazo de spec 021), US1/US2: vinculación automática de
 * Mercado Libre resolviendo el SKU vigente contra el catálogo en vivo del
 * vendedor conectado (`scan` search + multiget), en vez de `ml_orden_items`.
 * FR-001..FR-009 (spec.md).
 */
class MercadoLibreVinculacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 555,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk',
            'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    /** @var array<int, string> */
    private array $catalogoIds = [];

    /** @var array<string, array{sku: ?string, status?: string, variations?: array, titulo?: string}> */
    private array $catalogoItems = [];

    private bool $catalogoFakeRegistrado = false;

    /** Los `query` params van en la URL (no en el body), no se leen con `$request['clave']`. */
    private static function queryParam(ClientRequest $request, string $clave): ?string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return $params[$clave] ?? null;
    }

    /**
     * Simula el catálogo en vivo con una única página de `scan` (todos los
     * `$ids` en una sola respuesta, `results` vacío en la segunda llamada) y
     * el multiget resolviendo cada id contra `$itemsPorId`. `Http::fake()`
     * acumula stubs en vez de reemplazarlos (Factory::stubUrl hace merge), por
     * eso el registro de los closures ocurre una sola vez por test — llamadas
     * subsiguientes (US2: SKU corregido entre corridas) sólo actualizan el
     * catálogo simulado que esos mismos closures leen.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, array{sku: ?string, status?: string, variations?: array, titulo?: string}>  $itemsPorId
     */
    private function fakeCatalogo(array $ids, array $itemsPorId): void
    {
        $this->catalogoIds = $ids;
        $this->catalogoItems = $itemsPorId;

        if ($this->catalogoFakeRegistrado) {
            return;
        }

        $this->catalogoFakeRegistrado = true;

        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => function (ClientRequest $request) {
                if (self::queryParam($request, 'scroll_id')) {
                    return Http::response(['paging' => ['total' => count($this->catalogoIds)], 'scroll_id' => 'scroll-fin', 'results' => []]);
                }

                return Http::response(['paging' => ['total' => count($this->catalogoIds)], 'scroll_id' => 'scroll-1', 'results' => $this->catalogoIds]);
            },
            'api.mercadolibre.com/items*' => function (ClientRequest $request) {
                $idsSolicitados = explode(',', self::queryParam($request, 'ids'));
                $itemsPorId = $this->catalogoItems;

                return Http::response(array_map(fn (string $id) => [
                    'code' => 200,
                    'body' => [
                        'id' => $id,
                        'title' => $itemsPorId[$id]['titulo'] ?? 'Publicación '.$id,
                        'status' => $itemsPorId[$id]['status'] ?? 'active',
                        'variations' => $itemsPorId[$id]['variations'] ?? [],
                        'attributes' => ($itemsPorId[$id]['sku'] ?? null) !== null
                            ? [['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => $itemsPorId[$id]['sku']]]
                            : [],
                    ],
                ], $idsSolicitados));
            },
        ]);
    }

    public function test_publicacion_sin_ninguna_orden_sincronizada_se_vincula_por_sku_del_catalogo_en_vivo(): void
    {
        $producto = Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(0, $resumen['fallidas']);
        $this->assertDatabaseHas('ml_publicacion_producto', [
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id,
        ]);
        $this->assertDatabaseCount('ml_orden_items', 0);
    }

    public function test_sku_sin_match_deja_pendiente_con_motivo(): void
    {
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '999999']]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('MLA1', $resumen['detalle_fallidas'][0]['referencia']);
        $this->assertSame('producto_no_encontrado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_publicacion_sin_seller_sku_tiene_motivo_sin_sku(): void
    {
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => null]]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('sin_sku', $resumen['detalle_fallidas'][0]['motivo']);
    }

    public function test_dos_publicaciones_con_el_mismo_sku_solo_la_primera_se_vincula(): void
    {
        // Caso real confirmado en research.md R3 (dos publicaciones compartiendo el SKU "KO-23423"): acá
        // se usa un SKU numérico equivalente porque el matching resuelve contra `producto.id` (int).
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA-A', 'MLA-B'], [
            'MLA-A' => ['sku' => '9010'],
            'MLA-B' => ['sku' => '9010'],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertDatabaseCount('ml_publicacion_producto', 1);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertSame('producto', $resumen['detalle_fallidas'][0]['detalle']);
    }

    public function test_publicacion_con_variantes_queda_excluida(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA-VAR'], ['MLA-VAR' => ['sku' => '9010', 'variations' => [['id' => 1]]]]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['total']);
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_publicacion_cerrada_excluida_y_pausada_incluida(): void
    {
        Producto::factory()->create(['id' => 9010]);
        Producto::factory()->create(['id' => 9011]);
        $this->fakeCatalogo(['MLA-CLOSED', 'MLA-PAUSED'], [
            'MLA-CLOSED' => ['sku' => '9010', 'status' => 'closed'],
            'MLA-PAUSED' => ['sku' => '9011', 'status' => 'paused'],
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA-PAUSED', 'producto_id' => 9011]);
        $this->assertDatabaseMissing('ml_publicacion_producto', ['ml_item_id' => 'MLA-CLOSED']);
    }

    public function test_producto_inactivo_se_vincula_igual(): void
    {
        $producto = Producto::factory()->create(['id' => 9010, 'activo' => false]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
    }

    public function test_reintentar_la_corrida_no_modifica_lo_ya_vinculado(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->firstOrFail();

        // La segunda corrida vuelve a recorrer el catálogo completo (MLA1 sigue existiendo en Mercado
        // Libre), pero queda excluida antes del multiget por ya estar vinculada.
        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['total']);
        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertDatabaseCount('ml_publicacion_producto', 1);
        $this->assertDatabaseHas('ml_publicacion_producto', ['id' => $vinculo->id, 'ml_item_id' => 'MLA1']);
    }

    public function test_vinculo_existente_de_otra_publicacion_no_se_toca(): void
    {
        $productoExistente = Producto::factory()->create(['id' => 9000]);
        $vinculoExistente = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-VIEJA', 'producto_id' => $productoExistente->id, 'titulo_ml' => 'Vieja',
        ]);

        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertDatabaseHas('ml_publicacion_producto', [
            'id' => $vinculoExistente->id, 'ml_item_id' => 'MLA-VIEJA', 'producto_id' => $productoExistente->id, 'titulo_ml' => 'Vieja',
        ]);
    }

    public function test_recorrido_scan_de_dos_paginas_se_agota_completo_antes_de_procesar(): void
    {
        Producto::factory()->create(['id' => 9010]);
        Producto::factory()->create(['id' => 9011]);

        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::sequence()
                ->push(['paging' => ['total' => 2], 'scroll_id' => 'scroll-1', 'results' => ['MLA1']])
                ->push(['paging' => ['total' => 2], 'scroll_id' => 'scroll-2', 'results' => ['MLA2']])
                ->push(['paging' => ['total' => 2], 'scroll_id' => 'scroll-3', 'results' => []]),
            'api.mercadolibre.com/items*' => function (ClientRequest $request) {
                $idsSolicitados = explode(',', self::queryParam($request, 'ids'));
                $skus = ['MLA1' => '9010', 'MLA2' => '9011'];

                return Http::response(array_map(fn (string $id) => [
                    'code' => 200,
                    'body' => ['id' => $id, 'title' => 'Publicación '.$id, 'status' => 'active', 'variations' => [],
                        'attributes' => [['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => $skus[$id]]]],
                ], $idsSolicitados));
            },
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(2, $resumen['total']);
        $this->assertSame(2, $resumen['vinculadas']);
        Http::assertSentCount(3 + 1); // 3 páginas de scan + 1 multiget (ambos ids caben en un solo chunk de 20).
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA1', 'producto_id' => 9010]);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA2', 'producto_id' => 9011]);
    }

    public function test_si_el_scan_falla_a_mitad_de_camino_se_aborta_sin_crear_ningun_vinculo(): void
    {
        Producto::factory()->create(['id' => 9010]);

        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::sequence()
                ->push(['paging' => ['total' => 1], 'scroll_id' => 'scroll-1', 'results' => ['MLA1']])
                ->push(['message' => 'Bad request'], 400),
        ]);

        $this->expectException(VinculacionAutomaticaFallidaException::class);

        try {
            app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        } finally {
            $this->assertDatabaseCount('ml_publicacion_producto', 0);
        }
    }

    public function test_si_el_multiget_falla_se_aborta_sin_crear_ningun_vinculo(): void
    {
        Producto::factory()->create(['id' => 9010]);

        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => function (ClientRequest $request) {
                if (self::queryParam($request, 'scroll_id')) {
                    return Http::response(['paging' => ['total' => 1], 'scroll_id' => 'scroll-fin', 'results' => []]);
                }

                return Http::response(['paging' => ['total' => 1], 'scroll_id' => 'scroll-1', 'results' => ['MLA1']]);
            },
            'api.mercadolibre.com/items*' => Http::response(['message' => 'Bad request'], 400),
        ]);

        $this->expectException(VinculacionAutomaticaFallidaException::class);

        try {
            app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        } finally {
            $this->assertDatabaseCount('ml_publicacion_producto', 0);
        }
    }

    public function test_el_vinculo_creado_queda_con_stock_y_precio_pendiente_en_su_default(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertFalse($vinculo->precio_pendiente);
    }

    /** US2: el SKU corregido en Mercado Libre se refleja en la próxima corrida, sin rastro del intento fallido. */
    public function test_sku_corregido_en_mercadolibre_se_refleja_en_la_proxima_corrida(): void
    {
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => 'A']]);
        $primeraCorrida = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $primeraCorrida['fallidas']);
        $this->assertSame('producto_no_encontrado', $primeraCorrida['detalle_fallidas'][0]['motivo']);
        $this->assertDatabaseCount('ml_publicacion_producto', 0);

        $producto = Producto::factory()->create(['id' => 9010]);
        $this->fakeCatalogo(['MLA1'], ['MLA1' => ['sku' => '9010']]);
        $segundaCorrida = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $segundaCorrida['vinculadas']);
        $this->assertSame(0, $segundaCorrida['fallidas']);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
    }
}
