<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\CambiadorTipoPublicacion;
use App\Services\MercadoLibre\SincronizadorPrecios;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 115 (US1): cambio de tipo de publicación Clásica ↔ Premium.
 *
 * Lo que fijan estos tests es la regla de FR-008: la publicación nunca queda, ni siquiera un
 * instante, con un precio menor al de su tipo. Por eso se verifica el ORDEN de las llamadas a ML y
 * no sólo el estado final: un orden invertido termina igual de bien cuando todo sale bien, y deja
 * una Premium con precio de Clásica cuando el segundo paso falla — el incidente del 25/08/2026.
 */
class CambioTipoPublicacionTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $general;

    private ListaPrecio $premium;

    /** @var list<string> "METODO /ruta" de cada request a ML, en orden. */
    private array $llamadas = [];

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
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
    }

    /**
     * Precios cargados ANTES de crear el vínculo: así el observer de precios no tiene a quién
     * empujarle nada y las únicas llamadas a ML son las del cambio de tipo.
     */
    private function vinculo(string $tipo = 'gold_special', ?float $general = 100_000, ?float $premium = 145_350, ?float $publicado = 100_000): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create(['codigo' => 'TAP-01']);

        if ($general !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => $general]);
        }
        if ($premium !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => $premium]);
        }

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA123', 'producto_id' => $producto->id, 'titulo_ml' => 'Canilla',
            'listing_type_id' => $tipo, 'precio_publicado' => $publicado, 'precio_publicado_en' => $publicado ? now() : null,
        ]);
    }

    /** Estado del Mercado Libre simulado; `fakeMl()` lo reemplaza entre pasos de un mismo test. */
    private array $ml = [];

    private bool $fakeRegistrado = false;

    /**
     * Mercado Libre simulado. `$tipoEnMl` es el tipo vigente; las escrituras se pueden hacer fallar.
     * El fake se registra una sola vez y lee `$this->ml`: `Http::fake()` acumula stubs y el primero
     * registrado gana, así que volver a llamarlo no cambiaría el comportamiento.
     */
    private function fakeMl(string $tipoEnMl = 'gold_special', array $disponibles = ['gold_pro', 'gold_special'], bool $fallaTipo = false, bool $fallaPrecio = false): void
    {
        $this->llamadas = [];
        $this->ml = compact('tipoEnMl', 'disponibles', 'fallaTipo', 'fallaPrecio');

        if ($this->fakeRegistrado) {
            return;
        }
        $this->fakeRegistrado = true;

        Http::fake(function (Request $request) {
            $ml = $this->ml;
            $ruta = parse_url($request->url(), PHP_URL_PATH);
            $this->llamadas[] = $request->method().' '.$ruta;

            if ($request->method() === 'GET' && (str_ends_with($ruta, '/available_upgrades') || str_ends_with($ruta, '/available_downgrades'))) {
                return Http::response(array_map(fn ($id) => ['site_id' => 'MLA', 'id' => $id, 'name' => $id], $ml['disponibles']), 200);
            }

            if ($request->method() === 'GET') {
                return Http::response(['id' => 'MLA123', 'listing_type_id' => $ml['tipoEnMl'], 'status' => 'active'], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($ruta, '/listing_type')) {
                if ($ml['fallaTipo']) {
                    return Http::response(['message' => 'listing_type not allowed', 'error' => 'bad_request'], 400);
                }
                $this->ml['tipoEnMl'] = $request['id'];

                return Http::response(['id' => 'MLA123', 'listing_type_id' => $request['id']], 200);
            }

            if ($request->method() === 'PUT') {
                return $ml['fallaPrecio']
                    ? Http::response(['message' => 'price invalid', 'error' => 'bad_request'], 400)
                    : Http::response(['id' => 'MLA123', 'price' => $request['price']], 200);
            }

            return Http::response([], 404);
        });
    }

    private function escrituras(): array
    {
        return array_values(array_filter($this->llamadas, fn ($l) => ! str_starts_with($l, 'GET ')));
    }

    private function cambiador(): CambiadorTipoPublicacion
    {
        return app(CambiadorTipoPublicacion::class);
    }

    // ───────────────────────────── T007: caminos felices ─────────────────────────────

    public function test_a_premium_sube_el_precio_antes_de_cambiar_el_tipo(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('cambiado', $r['resultado']);
        $this->assertSame(['PUT /items/MLA123', 'POST /items/MLA123/listing_type'], $this->escrituras());

        $vinculo->refresh();
        $this->assertSame('gold_pro', $vinculo->listing_type_id);
        $this->assertEqualsWithDelta(145_350, (float) $vinculo->precio_publicado, 0.01);
        $this->assertFalse($vinculo->precio_pendiente);
    }

    public function test_a_clasica_cambia_el_tipo_antes_de_bajar_el_precio_y_no_lo_retiene_el_corte(): void
    {
        // Caída del 31%: con el corte de spec 084 activo, un envío automático quedaría retenido.
        $vinculo = $this->vinculo('gold_pro', publicado: 145_350);
        $this->fakeMl('gold_pro');

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_special', auth()->id());

        $this->assertSame('cambiado', $r['resultado']);
        $this->assertSame(['POST /items/MLA123/listing_type', 'PUT /items/MLA123'], $this->escrituras());
        $this->assertSame(0, MercadoLibreRetencionPrecio::count());

        $vinculo->refresh();
        $this->assertSame('gold_special', $vinculo->listing_type_id);
        $this->assertEqualsWithDelta(100_000, (float) $vinculo->precio_publicado, 0.01);
    }

    public function test_si_el_precio_destino_ya_es_el_publicado_solo_cambia_el_tipo(): void
    {
        $vinculo = $this->vinculo(publicado: 145_350);
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('cambiado', $r['resultado']);
        $this->assertSame(['POST /items/MLA123/listing_type'], $this->escrituras());
    }

    // ───────────────────────────── T008: bloqueos sin escrituras ─────────────────────────────

    public function test_sin_lista_premium_configurada_bloquea(): void
    {
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id_premium' => null]);
        $vinculo = $this->vinculo();
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertStringContainsString('Premium', $r['mensaje']);
        $this->assertSame([], $this->escrituras());
    }

    public function test_producto_sin_precio_premium_bloquea(): void
    {
        $vinculo = $this->vinculo(premium: null);
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertStringContainsString('ML Premium', $r['mensaje']);
        $this->assertSame([], $this->escrituras());
        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
    }

    public function test_a_clasica_sin_precio_en_la_lista_general_bloquea(): void
    {
        $vinculo = $this->vinculo('gold_pro', general: null, publicado: 145_350);
        $this->fakeMl('gold_pro');

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_special', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertSame([], $this->escrituras());
    }

    public function test_destino_no_disponible_en_ml_bloquea(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl(disponibles: ['gold_premium']);

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertStringContainsString('no ofrece', $r['mensaje']);
        $this->assertSame([], $this->escrituras());
    }

    public function test_tipo_fuera_de_alcance_bloquea(): void
    {
        $vinculo = $this->vinculo('free');
        $this->fakeMl('free');

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_vinculo_sin_producto_bloquea(): void
    {
        // La base no admite un vínculo sin producto (FK NOT NULL); el servicio igual lo contempla
        // porque la relación puede venir vacía si el producto se borra con el vínculo en memoria.
        $vinculo = $this->vinculo();
        $vinculo->setRelation('producto', null);
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_modo_solo_lectura_bloquea(): void
    {
        $vinculo = $this->vinculo();
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertStringContainsString('sólo lectura', $r['mensaje']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_funcion_desactivada_bloquea(): void
    {
        $vinculo = $this->vinculo();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);
        $this->fakeMl();

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('bloqueado', $r['resultado']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_con_el_candado_de_precios_tomado_responde_409_sin_escrituras(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl();
        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 60);
        $lock->get();

        try {
            $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.ejecutar', $vinculo), ['destino' => 'gold_pro'])
                ->assertStatus(409)
                ->assertJson(['ok' => false, 'resultado' => 'bloqueado']);
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->llamadas);
    }

    // ───────────────────────────── T009: fallas y recuperación ─────────────────────────────

    public function test_a_premium_con_el_tipo_rechazado_restituye_el_precio_anterior(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl(fallaTipo: true);

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('revertido', $r['resultado']);
        $this->assertSame(['PUT /items/MLA123', 'POST /items/MLA123/listing_type', 'PUT /items/MLA123'], $this->escrituras());

        $vinculo->refresh();
        $this->assertSame('gold_special', $vinculo->listing_type_id);
        $this->assertEqualsWithDelta(100_000, (float) $vinculo->precio_publicado, 0.01);
        $this->assertFalse($vinculo->precio_pendiente);
    }

    public function test_a_premium_rechazado_sin_precio_anterior_queda_pendiente(): void
    {
        $vinculo = $this->vinculo(publicado: null);
        $this->fakeMl(fallaTipo: true);

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('revertido', $r['resultado']);
        $this->assertSame(['PUT /items/MLA123', 'POST /items/MLA123/listing_type'], $this->escrituras());
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
    }

    public function test_a_clasica_con_el_precio_rechazado_queda_clasica_con_precio_pendiente(): void
    {
        $vinculo = $this->vinculo('gold_pro', publicado: 145_350);
        $this->fakeMl('gold_pro', fallaPrecio: true);

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_special', auth()->id());

        $this->assertSame('cambiado_precio_pendiente', $r['resultado']);

        $vinculo->refresh();
        $this->assertSame('gold_special', $vinculo->listing_type_id);
        $this->assertTrue($vinculo->precio_pendiente);
        // Sigue publicada con el precio Premium: cobra de más, nunca de menos.
        $this->assertEqualsWithDelta(145_350, (float) $vinculo->precio_publicado, 0.01);
    }

    public function test_a_clasica_con_el_tipo_rechazado_no_toca_el_precio(): void
    {
        $vinculo = $this->vinculo('gold_pro', publicado: 145_350);
        $this->fakeMl('gold_pro', fallaTipo: true);

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_special', auth()->id());

        $this->assertSame('fallido', $r['resultado']);
        $this->assertSame(['POST /items/MLA123/listing_type'], $this->escrituras());
        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
    }

    public function test_si_ml_ya_tenia_el_destino_actualiza_el_vinculo_sin_escrituras(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl('gold_pro');

        $r = $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->assertSame('sin_cambios', $r['resultado']);
        $this->assertSame([], $this->escrituras());
        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
    }

    public function test_una_retencion_abierta_queda_reemplazada(): void
    {
        $vinculo = $this->vinculo('gold_pro', publicado: 145_350);
        $retencion = MercadoLibreRetencionPrecio::create([
            'ml_publicacion_producto_id' => $vinculo->id, 'precio_propuesto' => 90_000, 'precio_publicado' => 145_350,
            'caida_pct' => 38.08, 'lista_precio_id' => $this->general->id, 'motivo' => 'supera_umbral',
            'umbral_pct' => 20, 'estado' => 'abierta',
        ]);
        $this->fakeMl('gold_pro');

        $this->cambiador()->ejecutar($vinculo, 'gold_special', auth()->id());

        $this->assertSame('reemplazada', $retencion->fresh()->estado);
    }

    public function test_cada_intento_queda_en_el_historial_con_usuario(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl();
        $this->cambiador()->ejecutar($vinculo, 'gold_pro', auth()->id());

        $this->fakeMl('gold_pro', disponibles: []);
        $this->cambiador()->ejecutar($vinculo->fresh(), 'gold_special', auth()->id());

        $filas = MercadoLibreCambioTipoPublicacion::orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertSame(['cambiado', 'bloqueado'], $filas->pluck('resultado')->all());
        $this->assertSame([auth()->id(), auth()->id()], $filas->pluck('usuario_id')->all());

        $primera = $filas->first();
        $this->assertSame('MLA123', $primera->ml_item_id);
        $this->assertSame('gold_special', $primera->tipo_anterior);
        $this->assertSame('gold_pro', $primera->tipo_destino);
        $this->assertEqualsWithDelta(100_000, (float) $primera->precio_anterior, 0.01);
        $this->assertEqualsWithDelta(145_350, (float) $primera->precio_nuevo, 0.01);
        $this->assertSame($this->premium->id, $primera->lista_precio_id);
        $this->assertNull($primera->lote_id);
    }

    // ───────────────────────────── T010: contratos HTTP ─────────────────────────────

    public function test_previsualizacion_devuelve_el_contrato(): void
    {
        $vinculo = $this->vinculo(publicado: null);
        $this->fakeMl();

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', [$vinculo, 'destino' => 'gold_pro']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'puede_confirmar' => true,
                'motivo_bloqueo' => null,
                'vinculo' => ['id' => $vinculo->id, 'ml_item_id' => 'MLA123', 'producto' => ['codigo' => 'TAP-01']],
                'tipo_actual' => 'gold_special', 'tipo_actual_etiqueta' => 'Clásica',
                'tipo_destino' => 'gold_pro', 'tipo_destino_etiqueta' => 'Premium',
                'precio_actual' => null,
                'precio_destino' => 145350,
                'lista_destino' => ['id' => $this->premium->id, 'nombre' => 'ML Premium'],
                'sin_cambios' => false,
                'aviso_comision' => true,
            ]);

        $this->assertSame([], $this->escrituras());
    }

    public function test_previsualizacion_a_clasica_no_avisa_comision_y_muestra_el_bloqueo(): void
    {
        $vinculo = $this->vinculo('gold_pro', general: null);
        $this->fakeMl('gold_pro');

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', [$vinculo, 'destino' => 'gold_special']))
            ->assertOk()
            ->assertJson(['puede_confirmar' => false, 'aviso_comision' => false])
            ->assertJsonPath('motivo_bloqueo', fn ($m) => str_contains($m, 'no tiene precio'));
    }

    public function test_previsualizacion_de_un_vinculo_sin_clasificar_resuelve_el_tipo_y_propone_el_opuesto(): void
    {
        $vinculo = $this->vinculo(tipo: 'gold_special');
        $vinculo->update(['listing_type_id' => null]);
        $this->fakeMl('gold_pro');

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', $vinculo))
            ->assertOk()
            ->assertJson(['tipo_actual' => 'gold_pro', 'tipo_destino' => 'gold_special', 'puede_confirmar' => true]);

        $this->assertSame('gold_pro', $vinculo->fresh()->listing_type_id);
    }

    public function test_destino_invalido_da_422(): void
    {
        $vinculo = $this->vinculo();
        $this->fakeMl();

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', [$vinculo, 'destino' => 'free']))->assertStatus(422);
        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', $vinculo))->assertStatus(422);
        $this->postJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.ejecutar', $vinculo), ['destino' => 'gold_premium'])->assertStatus(422);
    }

    public function test_codigos_http_de_la_ejecucion(): void
    {
        $vinculo = $this->vinculo();
        $ruta = route('ingresos.mercadolibre.vinculaciones.cambioTipo.ejecutar', $vinculo);

        $this->fakeMl(disponibles: []);
        $this->postJson($ruta, ['destino' => 'gold_pro'])->assertStatus(422)->assertJson(['resultado' => 'bloqueado']);

        $this->fakeMl(fallaTipo: true);
        $this->postJson($ruta, ['destino' => 'gold_pro'])->assertStatus(502)->assertJson(['resultado' => 'revertido']);

        $this->fakeMl();
        $this->postJson($ruta, ['destino' => 'gold_pro'])->assertOk()->assertJson(['ok' => true, 'resultado' => 'cambiado']);

        $this->fakeMl('gold_pro');
        $this->postJson($ruta, ['destino' => 'gold_pro'])->assertOk()->assertJson(['resultado' => 'sin_cambios']);
    }

    public function test_error_de_red_al_previsualizar_da_502(): void
    {
        $vinculo = $this->vinculo();
        Http::fake(['api.mercadolibre.com/*' => Http::response(['message' => 'not_found'], 404)]);
        $this->fakeRegistrado = true;

        $this->getJson(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', [$vinculo, 'destino' => 'gold_pro']))
            ->assertStatus(502)
            ->assertJson(['ok' => false]);
    }
}
