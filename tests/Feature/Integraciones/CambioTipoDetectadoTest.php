<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorPrecios;
use App\Services\MercadoLibre\SincronizadorTiposPublicacion;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 116 (US2): un cambio de tipo hecho desde Mercado Libre y detectado en la revisión de tipos.
 *
 * Lo que fijan estos tests es que el precio acompañe al tipo: la publicación que alguien pasó de
 * Premium a Clásica en ML tiene que quedar con el precio de la lista general (y al revés), y si en ese
 * momento no se puede enviar el precio el tipo no se consolida — consolidarlo sin precio es el bug
 * original, porque el cambio deja de detectarse (FR-008).
 */
class CambioTipoDetectadoTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $general;

    private ListaPrecio $premium;

    /** Tipo vigente en el ML simulado, por ítem. */
    private array $tiposEnMl = [];

    private bool $fallaPrecio = false;

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
            'corte_precios_activo' => true,
            'umbral_caida_precio_pct' => 20,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        Http::fake(function (Request $request) {
            $ruta = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $ruta === '/items') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                return Http::response(collect(explode(',', $q['ids'] ?? ''))->map(fn ($id) => [
                    'code' => 200,
                    'body' => ['id' => $id, 'listing_type_id' => $this->tiposEnMl[$id] ?? 'gold_special'],
                ])->all(), 200);
            }

            if ($request->method() === 'PUT') {
                return $this->fallaPrecio
                    ? Http::response(['message' => 'price invalid', 'error' => 'bad_request'], 400)
                    : Http::response(['price' => $request['price']], 200);
            }

            return Http::response([], 404);
        });
    }

    /** Precios cargados ANTES del vínculo: el observer de precios no tiene a quién empujarles nada. */
    private function vinculo(string $mlItemId, ?string $tipo, ?float $general = 100_000, ?float $premium = 145_350, ?float $publicado = 145_350): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        if ($general !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => $general]);
        }
        if ($premium !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => $premium]);
        }

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $mlItemId, 'producto_id' => $producto->id, 'titulo_ml' => 'Botiquín',
            'listing_type_id' => $tipo, 'precio_publicado' => $publicado, 'precio_publicado_en' => $publicado ? now() : null,
        ]);
    }

    private function revisar(): array
    {
        return app(SincronizadorTiposPublicacion::class)->sincronizarTodos();
    }

    private function puts(): array
    {
        return Http::recorded(fn ($r) => $r->method() === 'PUT')->map(fn ($par) => (float) $par[0]['price'])->values()->all();
    }

    public function test_premium_pasada_a_clasica_en_ml_publica_el_precio_general_aunque_baje_mas_del_umbral(): void
    {
        // El caso real del 28/10/2026: MLA1578044032 quedó Clásica con el precio Premium. Baja del 31%.
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_special'];

        $r = $this->revisar();

        $vinculo->refresh();
        $this->assertSame('gold_special', $vinculo->listing_type_id);
        $this->assertSame([100_000.0], $this->puts());
        $this->assertEqualsWithDelta(100_000, (float) $vinculo->precio_publicado, 0.01);
        $this->assertSame(0, MercadoLibreRetencionPrecio::count());
        $this->assertSame(1, $r['cambios_procesados']);

        $fila = MercadoLibreCambioTipoPublicacion::sole();
        $this->assertSame('mercadolibre', $fila->origen);
        $this->assertNull($fila->usuario_id);
        $this->assertSame('cambiado', $fila->resultado);
        $this->assertSame('gold_pro', $fila->tipo_anterior);
        $this->assertSame('gold_special', $fila->tipo_destino);
        $this->assertEqualsWithDelta(145_350, (float) $fila->precio_anterior, 0.01);
        $this->assertEqualsWithDelta(100_000, (float) $fila->precio_nuevo, 0.01);
        $this->assertSame($this->general->id, $fila->lista_precio_id);
    }

    public function test_clasica_pasada_a_premium_en_ml_publica_el_precio_premium(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_special', publicado: 100_000);
        $this->tiposEnMl = ['MLA1' => 'gold_pro'];

        $this->revisar();

        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
        $this->assertSame([145_350.0], $this->puts());
        $this->assertSame($this->premium->id, MercadoLibreCambioTipoPublicacion::sole()->lista_precio_id);
    }

    public function test_premium_sin_precio_en_la_lista_premium_cae_en_la_general_y_lo_dice(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_special', premium: null, publicado: 90_000);
        $this->tiposEnMl = ['MLA1' => 'gold_pro'];

        $this->revisar();

        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
        $this->assertSame([100_000.0], $this->puts());
        $this->assertStringContainsString('lista Premium', MercadoLibreCambioTipoPublicacion::sole()->mensaje);
    }

    public function test_si_el_precio_del_tipo_nuevo_ya_es_el_publicado_no_envia_y_cierra_la_retencion(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro', publicado: 100_000);
        $retencion = MercadoLibreRetencionPrecio::create([
            'ml_publicacion_producto_id' => $vinculo->id, 'precio_propuesto' => 70_000, 'precio_publicado' => 100_000,
            'caida_pct' => 30, 'lista_precio_id' => $this->general->id, 'motivo' => 'supera_umbral',
            'umbral_pct' => 20, 'estado' => 'abierta',
        ]);
        $this->tiposEnMl = ['MLA1' => 'gold_special'];

        $this->revisar();

        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
        $this->assertSame([], $this->puts());
        $this->assertSame('sin_cambios', MercadoLibreCambioTipoPublicacion::sole()->resultado);
        $this->assertSame('reemplazada', $retencion->fresh()->estado);
    }

    public function test_en_modo_solo_lectura_no_consolida_el_tipo_y_lo_procesa_en_la_proxima_revision(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_special'];
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $r = $this->revisar();

        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
        $this->assertSame([], $this->puts());
        $this->assertSame(0, MercadoLibreCambioTipoPublicacion::count());
        $this->assertSame(1, $r['cambios_postergados']);
        $this->assertTrue(MercadoLibreOperacionLog::where('operacion', 'sincronizar_tipo_publicacion')->where('resultado', 'bloqueada')->exists());

        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => false]);
        $this->revisar();

        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
        $this->assertSame([100_000.0], $this->puts());
    }

    public function test_con_el_candado_de_precios_tomado_no_consolida_el_tipo(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_special'];
        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 60);
        $lock->get();

        try {
            $r = $this->revisar();
        } finally {
            $lock->release();
        }

        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
        $this->assertSame([], $this->puts());
        $this->assertSame(0, MercadoLibreCambioTipoPublicacion::count());
        $this->assertSame(1, $r['cambios_postergados']);
    }

    public function test_si_ml_rechaza_el_precio_el_tipo_queda_y_el_precio_pendiente(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_special'];
        $this->fallaPrecio = true;

        $this->revisar();

        $vinculo->refresh();
        $this->assertSame('gold_special', $vinculo->listing_type_id);
        $this->assertTrue($vinculo->precio_pendiente);
        $this->assertSame('cambiado_precio_pendiente', MercadoLibreCambioTipoPublicacion::sole()->resultado);
    }

    public function test_la_primera_clasificacion_y_los_tipos_fuera_de_alcance_no_son_cambios_detectados(): void
    {
        $sinClasificar = $this->vinculo('MLA1', null);
        $aOroPremium = $this->vinculo('MLA2', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_pro', 'MLA2' => 'gold_premium'];

        $this->revisar();

        $this->assertSame('gold_pro', $sinClasificar->fresh()->listing_type_id);
        $this->assertSame('gold_premium', $aOroPremium->fresh()->listing_type_id);
        $this->assertSame([], $this->puts());
        $this->assertSame(0, MercadoLibreCambioTipoPublicacion::count());
    }

    public function test_un_producto_sin_precio_en_ninguna_lista_actualiza_el_tipo_sin_enviar(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro', general: null, premium: null);
        $this->tiposEnMl = ['MLA1' => 'gold_special'];

        $this->revisar();

        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
        $this->assertSame([], $this->puts());
        $fila = MercadoLibreCambioTipoPublicacion::sole();
        $this->assertSame('sin_cambios', $fila->resultado);
        $this->assertStringContainsString('sin precio para publicar', $fila->mensaje);
    }

    public function test_una_retencion_abierta_queda_reemplazada_al_publicar_el_precio(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $retencion = MercadoLibreRetencionPrecio::create([
            'ml_publicacion_producto_id' => $vinculo->id, 'precio_propuesto' => 80_000, 'precio_publicado' => 145_350,
            'caida_pct' => 45, 'lista_precio_id' => $this->general->id, 'motivo' => 'supera_umbral',
            'umbral_pct' => 20, 'estado' => 'abierta',
        ]);
        $this->tiposEnMl = ['MLA1' => 'gold_special'];

        $this->revisar();

        $this->assertSame([100_000.0], $this->puts());
        $this->assertSame('reemplazada', $retencion->fresh()->estado);
    }

    public function test_sin_cambios_de_tipo_no_toca_precios_ni_el_historial(): void
    {
        $vinculo = $this->vinculo('MLA1', 'gold_pro');
        $this->tiposEnMl = ['MLA1' => 'gold_pro'];

        $r = $this->revisar();

        $this->assertSame([], $this->puts());
        $this->assertSame(0, MercadoLibreCambioTipoPublicacion::count());
        $this->assertSame(0, $r['cambios_detectados']);
        $this->assertNotNull($vinculo->fresh()->listing_type_sincronizado_en);
    }
}
