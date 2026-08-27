<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorPrecios;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 084 T010–T011, T022 — el corte visto de punta a punta.
 *
 * `EvaluadorCambioPrecioTest` prueba que la decisión sea correcta; esto prueba que la decisión
 * **se respete**: que cuando dice "retener" no salga ningún PUT hacia Mercado Libre. Un evaluador
 * impecable al que nadie le hace caso no protege nada.
 */
class RetencionPrecioFlujoTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
            'umbral_caida_precio_pct' => 20,
            'corte_precios_activo' => true,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->lista = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $this->lista->id]);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);
    }

    private function publicacion(string $itemId, float $publicado, float $precioLista): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create([
            'producto_id' => $producto->id,
            'lista_precio_id' => $this->lista->id,
            'precio' => $precioLista,
        ]);

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $itemId,
            'producto_id' => $producto->id,
            'listing_type_id' => 'gold_special',
            'precio_publicado' => $publicado,
        ]);
    }

    /** Cuántos PUT de precio salieron hacia una publicación. */
    private function putsA(string $itemId): int
    {
        $n = 0;

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'PUT' && str_contains($request->url(), "/items/{$itemId}")
                && array_key_exists('price', $request->data())) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * El incidente del 25/08: una Premium recibiendo el precio de la lista Clásica, un 31% menos.
     * Con el corte no llega a la API.
     */
    public function test_el_incidente_del_25_08_queda_retenido_y_no_sale_ningun_put(): void
    {
        $v = $this->publicacion('MLA-PREMIUM', publicado: 317_743.34, precioLista: 218_607.42);

        $enviado = app(SincronizadorPrecios::class)->enviarUno($v, 218_607.42);

        $this->assertFalse($enviado, 'enviarUno() tiene que informar que no envió.');
        $this->assertSame(0, $this->putsA('MLA-PREMIUM'), 'No puede salir ningún PUT: el precio publicado queda intacto.');

        $retencion = $v->fresh()->retencionAbierta;
        $this->assertNotNull($retencion);
        $this->assertSame(MercadoLibreRetencionPrecio::MOTIVO_SUPERA_UMBRAL, $retencion->motivo);
        $this->assertEqualsWithDelta(31.20, (float) $retencion->caida_pct, 0.01);
        $this->assertEqualsWithDelta(20.00, (float) $retencion->umbral_pct, 0.01, 'Guarda el umbral vigente al retener.');
    }

    /** El incidente del 06/08: el precio dividido por 1000. Antes lo frenaba Mercado Libre, ahora nosotros. */
    public function test_el_incidente_del_06_08_queda_retenido(): void
    {
        $v = $this->publicacion('MLA-ESCALA', publicado: 262_252.00, precioLista: 262.26);

        app(SincronizadorPrecios::class)->enviarUno($v, 262.26);

        $this->assertSame(0, $this->putsA('MLA-ESCALA'));
        $this->assertEqualsWithDelta(99.90, (float) $v->fresh()->retencionAbierta->caida_pct, 0.01);
    }

    public function test_una_bajada_dentro_del_umbral_se_publica_normalmente(): void
    {
        $v = $this->publicacion('MLA-OK', publicado: 100_000, precioLista: 85_000);

        $this->assertTrue(app(SincronizadorPrecios::class)->enviarUno($v, 85_000));
        $this->assertSame(1, $this->putsA('MLA-OK'));
        $this->assertNull($v->fresh()->retencionAbierta);
        $this->assertEqualsWithDelta(85_000, (float) $v->fresh()->precio_publicado, 0.01,
            'Un envío exitoso deja la nueva referencia para el próximo corte.');
    }

    /** FR-009: una retención no puede arrastrar al resto de la corrida. */
    public function test_una_retencion_no_frena_a_las_demas_publicaciones(): void
    {
        $retenidas = [];
        $publicadas = [];

        for ($i = 1; $i <= 3; $i++) {
            $retenidas[] = $this->publicacion("MLA-R{$i}", publicado: 100_000, precioLista: 40_000);
        }
        for ($i = 1; $i <= 7; $i++) {
            $publicadas[] = $this->publicacion("MLA-P{$i}", publicado: 100_000, precioLista: 95_000);
        }

        foreach ($retenidas as $v) {
            app(SincronizadorPrecios::class)->enviarUno($v, 40_000);
        }
        foreach ($publicadas as $v) {
            app(SincronizadorPrecios::class)->enviarUno($v, 95_000);
        }

        $this->assertSame(3, MercadoLibreRetencionPrecio::abiertas()->count());

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(0, $this->putsA("MLA-R{$i}"));
        }
        for ($i = 1; $i <= 7; $i++) {
            $this->assertSame(1, $this->putsA("MLA-P{$i}"), "MLA-P{$i} tenía que publicarse igual.");
        }
    }

    /** FR-010: no se acumulan retenciones; la propuesta nueva reemplaza a la anterior. */
    public function test_una_propuesta_nueva_reemplaza_a_la_retencion_abierta(): void
    {
        $v = $this->publicacion('MLA-REEMPLAZO', publicado: 100_000, precioLista: 40_000);

        app(SincronizadorPrecios::class)->enviarUno($v, 40_000);
        $primera = $v->fresh()->retencionAbierta;

        app(SincronizadorPrecios::class)->enviarUno($v, 30_000);

        $this->assertSame(MercadoLibreRetencionPrecio::ESTADO_REEMPLAZADA, $primera->fresh()->estado);
        $this->assertSame(1, MercadoLibreRetencionPrecio::abiertas()->count(), 'Sólo puede quedar una abierta.');
        $this->assertEqualsWithDelta(30_000, (float) $v->fresh()->retencionAbierta->precio_propuesto, 0.01);
    }

    /**
     * Una propuesta nueva que ya no supera el umbral levanta la retención y se publica
     * (escenario 6 de la US1).
     */
    public function test_una_propuesta_dentro_del_umbral_levanta_la_retencion_y_publica(): void
    {
        $v = $this->publicacion('MLA-LEVANTA', publicado: 100_000, precioLista: 40_000);

        app(SincronizadorPrecios::class)->enviarUno($v, 40_000);
        $this->assertNotNull($v->fresh()->retencionAbierta);

        app(SincronizadorPrecios::class)->enviarUno($v, 95_000);

        $this->assertNull($v->fresh()->retencionAbierta, 'La retención se levanta.');
        $this->assertSame(1, $this->putsA('MLA-LEVANTA'));
    }

    /** FR-015 / Decisión 4: una retenida no la agarra el reintento de pendientes. */
    public function test_una_publicacion_retenida_no_aparece_entre_las_pendientes(): void
    {
        $v = $this->publicacion('MLA-PEND', publicado: 100_000, precioLista: 40_000);

        app(SincronizadorPrecios::class)->enviarUno($v, 40_000);

        $this->assertSame(0, MercadoLibrePublicacionProducto::pendientesPrecio()->count(),
            'Si apareciera como pendiente, el reintento publicaría justo lo que el corte frenó.');
    }
}
