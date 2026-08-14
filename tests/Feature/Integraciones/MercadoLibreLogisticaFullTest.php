<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\MercadoLibre\VinculadorAutomatico;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 065 · Phase 2 (clasificación de logística) y US1 (exclusión del push).
 *
 * Las respuestas mockeadas replican las capturas reales de
 * `specs/065-ml-deposito-full/contracts/api-mercadolibre.md`.
 */
class MercadoLibreLogisticaFullTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
    }

    private function crearVinculo(string $mlItemId, array $atributos = []): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        return MercadoLibrePublicacionProducto::create(array_merge(
            ['ml_item_id' => $mlItemId, 'producto_id' => $producto->id],
            $atributos
        ));
    }

    // ── Phase 2: clasificación ────────────────────────────────────────────────

    /** T005 · FR-001: el multiget existente persiste logistic_type e inventory_id, sin llamadas nuevas. */
    public function test_el_multiget_persiste_el_tipo_de_logistica_y_el_inventario(): void
    {
        $vinculo = $this->crearVinculo('MLA762900978');

        Http::fake([
            'api.mercadolibre.com/items*' => Http::response([[
                'code' => 200,
                'body' => [
                    'id' => 'MLA762900978',
                    'listing_type_id' => 'gold_pro',
                    'available_quantity' => 4,
                    'inventory_id' => 'TPCW64194',
                    'shipping' => ['mode' => 'me2', 'logistic_type' => 'fulfillment'],
                ],
            ]], 200),
        ]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $vinculo->refresh();
        $this->assertSame('fulfillment', $vinculo->logistic_type);
        $this->assertSame('TPCW64194', $vinculo->inventory_id);
        $this->assertNotNull($vinculo->logistica_sincronizada_en);
        $this->assertTrue($vinculo->esFull());
        // FR-001 / research R8: no se agrega ninguna llamada al multiget existente.
        Http::assertSentCount(1);
    }

    /** T005 · FR-004: ante fallo del chunk el vínculo conserva su último valor conocido. */
    public function test_falla_del_chunk_conserva_el_ultimo_tipo_de_logistica_conocido(): void
    {
        $vinculo = $this->crearVinculo('MLA1', [
            'logistic_type' => 'fulfillment', 'inventory_id' => 'TPCW64194',
            'logistica_sincronizada_en' => now()->subDays(2),
        ]);

        Http::fake(['api.mercadolibre.com/items*' => Http::response([], 500)]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $vinculo->refresh();
        $this->assertSame('fulfillment', $vinculo->logistic_type);
        $this->assertSame('TPCW64194', $vinculo->inventory_id);
    }

    /** T005 · FR-005: sin logistic_type en el body no se pisa con null lo ya conocido. */
    public function test_body_sin_logistica_no_pisa_el_valor_conocido(): void
    {
        $vinculo = $this->crearVinculo('MLA1', ['logistic_type' => 'fulfillment']);

        Http::fake(['api.mercadolibre.com/items*' => Http::response([[
            'code' => 200,
            'body' => ['id' => 'MLA1', 'listing_type_id' => 'gold_special'],
        ]], 200)]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $vinculo->refresh();
        $this->assertSame('fulfillment', $vinculo->logistic_type);
        $this->assertSame('gold_special', $vinculo->listing_type_id);
    }

    /** T005 · FR-005: null es no-Full — ante la duda, el sistema nunca asume Full. */
    public function test_sin_clasificar_es_no_full(): void
    {
        $vinculo = $this->crearVinculo('MLA1');

        $this->assertFalse($vinculo->esFull());
        $this->assertSame('Sin clasificar', $vinculo->logistica_etiqueta);
        $this->assertTrue(MercadoLibrePublicacionProducto::noFull()->whereKey($vinculo->id)->exists());
    }

    /** T005 · FR-005a: un valor desconocido es no-Full y se muestra tal cual, no se descarta. */
    public function test_valor_desconocido_es_no_full_y_se_muestra_tal_cual(): void
    {
        $vinculo = $this->crearVinculo('MLA1', ['logistic_type' => 'modalidad_nueva_de_ml']);

        $this->assertFalse($vinculo->esFull());
        $this->assertSame('modalidad_nueva_de_ml', $vinculo->logistica_etiqueta);
        $this->assertTrue(MercadoLibrePublicacionProducto::noFull()->whereKey($vinculo->id)->exists());
    }

    /**
     * T007b · FR-003: una publicación recién vinculada queda clasificada en el acto, sin
     * esperar a la corrida diaria — si no, una Full nueva recibiría stock indebidamente.
     * Se resuelve del multiget que `VinculadorAutomatico` ya hace, sin llamadas extra.
     */
    public function test_una_publicacion_recien_vinculada_ya_queda_clasificada(): void
    {
        Producto::factory()->create(['id' => 9010]);

        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => function (ClientRequest $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

                return Http::response(isset($params['scroll_id'])
                    ? ['scroll_id' => 'fin', 'results' => []]
                    : ['scroll_id' => 's1', 'results' => ['MLA762900978']]);
            },
            'api.mercadolibre.com/items*' => Http::response([[
                'code' => 200,
                'body' => [
                    'id' => 'MLA762900978',
                    'title' => 'Publicación Full',
                    'status' => 'active',
                    'variations' => [],
                    'listing_type_id' => 'gold_pro',
                    'inventory_id' => 'TPCW64194',
                    'shipping' => ['mode' => 'me2', 'logistic_type' => 'fulfillment'],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => '9010']],
                ],
            ]], 200),
        ]);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);

        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA762900978')->sole();
        $this->assertTrue($vinculo->esFull());
        $this->assertSame('TPCW64194', $vinculo->inventory_id);
        $this->assertNotNull($vinculo->logistica_sincronizada_en);
        // Regresión de la spec 050: el tipo de publicación se sigue determinando al vincular.
        $this->assertSame('gold_pro', $vinculo->listing_type_id);
    }

    // ── US1: exclusión del push de stock ──────────────────────────────────────

    private function crearVinculoPendiente(int $stockInicial, array $atributos = []): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(array_merge(
            ['ml_item_id' => 'MLA'.$producto->id, 'producto_id' => $producto->id],
            $atributos
        ));

        app(StockService::class)->ajustar($producto, null, Deposito::first(), $stockInicial, 'carga inicial');
        $vinculo->update(['stock_pendiente' => true]);

        return $vinculo;
    }

    /**
     * T008 · FR-006/FR-007: una publicación Full no recibe PUT, no queda marcada con error
     * y su pendiente se limpia — del otro lado no hay destino de escritura, así que
     * reintentarla eternamente sólo generaría ruido y llamadas fallidas.
     */
    public function test_una_publicacion_full_no_recibe_push_de_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $full = $this->crearVinculoPendiente(5, ['logistic_type' => 'fulfillment']);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        Http::assertNothingSent();
        $this->assertTrue($resultado['ok']);
        $this->assertSame(0, $resultado['actualizados']);
        $this->assertSame(0, $resultado['con_error']);
        $this->assertSame(1, $resultado['omitidos']);

        $full->refresh();
        $this->assertFalse($full->stock_pendiente);
        $this->assertNull($full->stock_error);
        $this->assertFalse($full->stock_requiere_intervencion);
    }

    /** T008 · SC-007: la publicación de logística propia se sigue enviando exactamente igual que hoy. */
    public function test_la_publicacion_de_logistica_propia_se_sigue_enviando(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->crearVinculoPendiente(5, ['logistic_type' => 'fulfillment']);
        $propia = $this->crearVinculoPendiente(7, ['logistic_type' => 'xd_drop_off']);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), $propia->ml_item_id)
            && $request['available_quantity'] === 7);

        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $resultado['omitidos']);
        $this->assertSame(7, $propia->fresh()->ultimo_stock_publicado);
    }

    /**
     * T008 · SC-007: sin ninguna publicación Full vinculada el mensaje de resultado tiene
     * que ser idéntico al de antes de esta feature — es el criterio de no-regresión.
     */
    public function test_sin_publicaciones_full_el_mensaje_es_identico_al_actual(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->crearVinculoPendiente(7, ['logistic_type' => 'xd_drop_off']);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(0, $resultado['omitidos']);
        $this->assertSame('1 productos actualizados en Mercado Libre.', $resultado['mensaje']);
        $this->assertSame(
            'OK: 1 productos actualizados, 0 con error.',
            MercadoLibreConfiguracion::actual()->stock_ultima_sync_resultado
        );
    }

    /** T008 · FR-008: con Full omitidas, el resultado lo informa en vez de esconderlo. */
    public function test_con_full_omitidas_el_resultado_lo_informa(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->crearVinculoPendiente(5, ['logistic_type' => 'fulfillment']);
        $this->crearVinculoPendiente(7, ['logistic_type' => 'xd_drop_off']);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(
            '1 productos actualizados en Mercado Libre, 1 omitidos por estar en Full.',
            $resultado['mensaje']
        );
        $this->assertSame(
            'OK: 1 productos actualizados, 0 con error, 1 omitidos por estar en Full.',
            MercadoLibreConfiguracion::actual()->stock_ultima_sync_resultado
        );
    }

    /** T008: la sincronización forzada (todos los vínculos, spec 035) excluye Full igual que el cron. */
    public function test_la_sincronizacion_forzada_tambien_excluye_full(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $full = $this->crearVinculoPendiente(5, ['logistic_type' => 'fulfillment']);
        $full->update(['stock_pendiente' => false]);
        $this->crearVinculoPendiente(7, ['logistic_type' => 'xd_drop_off']);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorStock::class)->sincronizarTodos();

        Http::assertSentCount(1);
        $this->assertSame(1, $resultado['omitidos']);
    }

    // ── US2: la clasificación visible en la grilla ────────────────────────────

    private function datatable(array $filtros = []): array
    {
        Deposito::firstOrCreate(['nombre' => 'Principal'], ['activo' => true]);

        return $this->getJson(route('ingresos.mercadolibre.vinculaciones.datatable', $filtros))
            ->assertOk()
            ->json('data');
    }

    /** T016 · FR-024: el endpoint devuelve la etiqueta legible y la marca de Full. */
    public function test_el_datatable_devuelve_las_columnas_de_logistica(): void
    {
        $this->crearVinculo('MLA1', ['logistic_type' => 'fulfillment']);
        $this->crearVinculo('MLA2', ['logistic_type' => 'xd_drop_off']);
        $this->crearVinculo('MLA3');

        $filas = collect($this->datatable())->keyBy('ml_item_id');

        $this->assertSame('Full', $filas['MLA1']['logistica_etiqueta']);
        $this->assertTrue($filas['MLA1']['es_full']);
        $this->assertSame('Colecta', $filas['MLA2']['logistica_etiqueta']);
        $this->assertFalse($filas['MLA2']['es_full']);
        $this->assertSame('Sin clasificar', $filas['MLA3']['logistica_etiqueta']);
        $this->assertNull($filas['MLA3']['logistic_type']);
    }

    /** T016 · FR-025: el filtro acota el listado server-side. */
    public function test_el_filtro_por_tipo_de_logistica_acota_el_listado(): void
    {
        $this->crearVinculo('MLA1', ['logistic_type' => 'fulfillment']);
        $this->crearVinculo('MLA2', ['logistic_type' => 'xd_drop_off']);
        $this->crearVinculo('MLA3');

        $this->assertSame(['MLA1'], array_column($this->datatable(['logistic_type' => 'fulfillment']), 'ml_item_id'));
        // `sin_clasificar` es el único valor que no viene de ML: representa el NULL.
        $this->assertSame(['MLA3'], array_column($this->datatable(['logistic_type' => 'sin_clasificar']), 'ml_item_id'));
        $this->assertCount(3, $this->datatable());
        $this->assertCount(3, $this->datatable(['logistic_type' => '']));
    }

    /** T005: los scopes parten el universo en dos sin dejar vínculos afuera. */
    public function test_los_scopes_es_full_y_no_full_son_complementarios(): void
    {
        $full = $this->crearVinculo('MLA1', ['logistic_type' => 'fulfillment']);
        $flex = $this->crearVinculo('MLA2', ['logistic_type' => 'self_service']);
        $sinClasificar = $this->crearVinculo('MLA3');

        $this->assertSame([$full->id], MercadoLibrePublicacionProducto::soloFull()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$flex->id, $sinClasificar->id],
            MercadoLibrePublicacionProducto::noFull()->pluck('id')->all()
        );
    }
}
