<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Jobs\CambioTipoPublicacionLote;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\CambiadorTipoPublicacion;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * spec 115 (US3): cambio de tipo masivo en segundo plano (FR-013..FR-015b).
 */
class CambioTipoPublicacionLoteTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $general;

    private ListaPrecio $premium;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        $this->general = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $this->premium = ListaPrecio::create(['nombre' => 'ML Premium', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
            'lista_precio_id' => $this->general->id,
            'lista_precio_id_premium' => $this->premium->id,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        Cache::forget(CambioTipoPublicacionLote::ESTADO_CACHE_KEY);
    }

    private function vinculo(string $mlItemId, string $tipo = 'gold_special', ?float $premium = 1450): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();
        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => 1000]);

        if ($premium !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => $premium]);
        }

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $mlItemId, 'producto_id' => $producto->id, 'listing_type_id' => $tipo, 'precio_publicado' => 1000,
        ]);
    }

    /** ML simulado: `$rechazan` son los ítems a los que ML les rechaza el cambio de tipo. */
    private function fakeMl(array $rechazan = []): void
    {
        Http::fake(function (Request $request) use ($rechazan) {
            $ruta = parse_url($request->url(), PHP_URL_PATH);
            preg_match('#/items/([^/]+)#', $ruta, $m);
            $item = $m[1] ?? '';

            if (str_ends_with($ruta, '/available_upgrades') || str_ends_with($ruta, '/available_downgrades')) {
                return Http::response([['id' => 'gold_pro'], ['id' => 'gold_special']], 200);
            }

            if ($request->method() === 'GET') {
                return Http::response(['id' => $item, 'listing_type_id' => 'gold_special', 'tags' => ['immediate_payment']], 200);
            }

            if (str_ends_with($ruta, '/listing_type')) {
                return in_array($item, $rechazan, true)
                    ? Http::response(['message' => 'not allowed'], 400)
                    : Http::response(['id' => $item, 'listing_type_id' => $request['id']], 200);
            }

            return Http::response(['id' => $item], 200);
        });
    }

    public function test_previsualizar_separa_cambiables_y_omitidas_sin_llamar_a_ml(): void
    {
        Http::fake();
        $a = $this->vinculo('MLA1');
        $b = $this->vinculo('MLA2');
        $sinPrecio = $this->vinculo('MLA3', premium: null);
        $yaPremium = $this->vinculo('MLA4', 'gold_pro');

        $resp = $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.previsualizar'), [
            'destino' => 'gold_pro', 'ids' => [$a->id, $b->id, $sinPrecio->id, $yaPremium->id],
        ])->assertOk()->assertJson(['ok' => true, 'puede_confirmar' => true, 'aviso_comision' => true]);

        $this->assertSame(['MLA1', 'MLA2'], array_column($resp->json('a_cambiar'), 'ml_item_id'));
        $omitidas = collect($resp->json('omitidas'))->keyBy('ml_item_id');
        $this->assertStringContainsString('ML Premium', $omitidas['MLA3']['motivo']);
        $this->assertStringContainsString('Ya es Premium', $omitidas['MLA4']['motivo']);
        Http::assertNothingSent();
    }

    public function test_sin_ninguna_cambiable_no_permite_confirmar(): void
    {
        $v = $this->vinculo('MLA1', 'gold_pro');

        $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.previsualizar'), ['destino' => 'gold_pro', 'ids' => [$v->id]])
            ->assertOk()
            ->assertJson(['puede_confirmar' => false]);
    }

    public function test_ids_vacios_o_mas_de_100_dan_422(): void
    {
        $ruta = route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.ejecutar');

        $this->postJson($ruta, ['destino' => 'gold_pro', 'ids' => []])->assertStatus(422);
        $this->postJson($ruta, ['destino' => 'gold_pro', 'ids' => range(1, 101)])->assertStatus(422);
        $this->postJson($ruta, ['destino' => 'free', 'ids' => [1]])->assertStatus(422);
    }

    public function test_ejecutar_encola_el_job_y_un_segundo_lote_da_409(): void
    {
        Queue::fake();
        $v = $this->vinculo('MLA1');
        $ruta = route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.ejecutar');

        $resp = $this->postJson($ruta, ['destino' => 'gold_pro', 'ids' => [$v->id]])->assertStatus(202)->assertJson(['ok' => true]);
        $this->assertNotEmpty($resp->json('lote_id'));
        Queue::assertPushed(CambioTipoPublicacionLote::class, fn ($job) => $job->loteId === $resp->json('lote_id') && $job->ids === [$v->id]);

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.estado'))->assertJson(['estado' => 'en_curso', 'total' => 1]);

        $this->postJson($ruta, ['destino' => 'gold_pro', 'ids' => [$v->id]])->assertStatus(409);
    }

    public function test_el_job_procesa_cada_publicacion_por_separado_y_deja_el_resumen(): void
    {
        $this->fakeMl(rechazan: ['MLA2']);
        $ok = $this->vinculo('MLA1');
        $rechazada = $this->vinculo('MLA2');
        $sinPrecio = $this->vinculo('MLA3', premium: null);

        (new CambioTipoPublicacionLote('lote-1', [$ok->id, $rechazada->id, $sinPrecio->id], 'gold_pro', auth()->id()))
            ->handle(app(CambiadorTipoPublicacion::class));

        $estado = Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY);
        $this->assertSame('terminado', $estado['estado']);
        $this->assertSame(3, $estado['procesadas']);
        $this->assertSame(1, $estado['resumen']['cambiado']);
        $this->assertSame(1, $estado['resumen']['revertido']);
        $this->assertSame(1, $estado['resumen']['bloqueado']);
        $this->assertEqualsCanonicalizing(['MLA2', 'MLA3'], array_column($estado['detalle'], 'ml_item_id'));

        $this->assertSame('gold_pro', $ok->fresh()->listing_type_id);
        $this->assertSame('gold_special', $rechazada->fresh()->listing_type_id);

        $filas = MercadoLibreCambioTipoPublicacion::all();
        $this->assertCount(3, $filas);
        $this->assertTrue($filas->every(fn ($f) => $f->lote_id === 'lote-1' && $f->usuario_id === auth()->id()));
    }

    /** Ajuste 16/10/2026: la opción de cuotas elegida en la confirmación viaja al job y se aplica a cada una. */
    public function test_el_lote_aplica_las_cuotas_elegidas(): void
    {
        Queue::fake();
        $v = $this->vinculo('MLA1');

        $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.previsualizar'), ['destino' => 'gold_pro', 'ids' => [$v->id]])
            ->assertJson(['cuotas_sugerida' => 'no-campaign', 'cuotas_opciones' => [['id' => '3x_campaign'], ['id' => 'no-campaign'], ['id' => '9x_campaign'], ['id' => '12x_campaign']]]);

        $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.ejecutar'), ['destino' => 'gold_pro', 'ids' => [$v->id], 'cuotas' => '9x_campaign'])
            ->assertStatus(202);
        Queue::assertPushed(CambioTipoPublicacionLote::class, fn ($job) => $job->cuotas === '9x_campaign');

        $this->fakeMl();
        (new CambioTipoPublicacionLote('lote-c', [$v->id], 'gold_pro', auth()->id(), '9x_campaign'))->handle(app(CambiadorTipoPublicacion::class));

        $this->assertSame(1, Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY)['resumen']['cambiado']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && ($r['tags'] ?? null) === ['9x_campaign', 'immediate_payment']);
        $this->assertSame('9x_campaign', MercadoLibreCambioTipoPublicacion::first()->cuotas);
    }

    /** SC-004: un lote de 50 termina holgado (con ML simulado, mide el costo propio del CRM). */
    public function test_un_lote_de_50_termina_en_menos_de_2_minutos(): void
    {
        $this->fakeMl();
        $ids = collect(range(1, 50))->map(fn ($i) => $this->vinculo('MLA'.$i)->id)->all();

        $inicio = microtime(true);
        (new CambioTipoPublicacionLote('lote-50', $ids, 'gold_pro', auth()->id()))->handle(app(CambiadorTipoPublicacion::class));

        $this->assertLessThan(120, microtime(true) - $inicio);
        $this->assertSame(50, Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY)['resumen']['cambiado']);
    }

    /** spec 116, US1: `available_downgrades` vacío (lo que ML devuelve para toda la cuenta) no frena el lote a Clásica. */
    public function test_el_lote_a_clasica_no_depende_de_available_downgrades(): void
    {
        Http::fake(function (Request $request) {
            $ruta = parse_url($request->url(), PHP_URL_PATH);
            preg_match('#/items/([^/]+)#', $ruta, $m);

            if (str_ends_with($ruta, '/available_downgrades')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET') {
                return Http::response(['id' => $m[1] ?? '', 'listing_type_id' => 'gold_pro', 'tags' => ['immediate_payment']], 200);
            }
            if (str_ends_with($ruta, '/listing_type')) {
                return Http::response(['id' => $m[1] ?? '', 'listing_type_id' => $request['id']], 200);
            }

            return Http::response(['id' => $m[1] ?? ''], 200);
        });
        $a = $this->vinculo('MLA1', 'gold_pro');
        $b = $this->vinculo('MLA2', 'gold_pro');

        (new CambioTipoPublicacionLote('lote-baja', [$a->id, $b->id], 'gold_special', auth()->id()))
            ->handle(app(CambiadorTipoPublicacion::class));

        $this->assertSame(2, Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY)['resumen']['cambiado']);
        $this->assertSame('gold_special', $a->fresh()->listing_type_id);
        $this->assertSame('gold_special', $b->fresh()->listing_type_id);
        Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/available_downgrades'));
    }
}
