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
use App\Services\MercadoLibre\ChequeoPreciosPublicados;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 084 T029–T030 — el chequeo CRM ↔ API.
 *
 * El test que más importa es el primero: **cero falsos positivos**. Un panel que muestra 30
 * publicaciones "desfasadas" todos los días se vuelve ruido y el día que aparezca una de verdad
 * nadie la va a ver. Ese error se cometió durante el diagnóstico del 26/08/2026.
 */
class ChequeoPreciosPublicadosTest extends TestCase
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

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->general = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $this->premium = ListaPrecio::create(['nombre' => 'ML Premium', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'lista_precio_id' => $this->general->id,
            'lista_precio_id_premium' => $this->premium->id,
        ]);
    }

    private function publicacion(string $itemId, string $tipo, float $general, ?float $premium): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => $general]);

        if ($premium !== null) {
            PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => $premium]);
        }

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $itemId, 'producto_id' => $producto->id, 'listing_type_id' => $tipo,
        ]);
    }

    private function respondeCon(array $precios): void
    {
        Http::fake(collect($precios)->mapWithKeys(fn ($precio, $item) => [
            "api.mercadolibre.com/items/{$item}*" => Http::response(['id' => $item, 'price' => $precio, 'status' => 'active'], 200),
        ])->all());
    }

    /**
     * El falso positivo que hay que evitar: una Premium con su precio Premium correcto. Comparada
     * contra la lista general aparecería desfasada un 31%.
     */
    public function test_una_premium_correcta_no_se_reporta_como_desfasada(): void
    {
        $this->publicacion('MLA-PREMIUM', 'gold_pro', general: 218_607.42, premium: 317_743.34);
        $this->publicacion('MLA-CLASICA', 'gold_special', general: 100_000, premium: 145_350);

        $this->respondeCon(['MLA-PREMIUM' => 317_743.34, 'MLA-CLASICA' => 100_000]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar();

        $this->assertSame(0, $r['resumen']['difieren'],
            'Comparar las Premium contra la lista general es el error que vuelve inservible al panel.');
        $this->assertSame(2, $r['resumen']['coinciden']);
    }

    public function test_una_diferencia_real_se_reporta(): void
    {
        $this->publicacion('MLA-MAL', 'gold_special', general: 100_000, premium: null);
        $this->respondeCon(['MLA-MAL' => 70_000]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar();

        $this->assertSame(1, $r['resumen']['difieren']);
        $this->assertSame(30_000.0, $r['diferencias'][0]['diferencia']);
    }

    /** FR-023: una retenida difiere a propósito. No es un problema, es el sistema funcionando. */
    public function test_una_retenida_va_aparte_y_no_cuenta_como_desfasaje(): void
    {
        $v = $this->publicacion('MLA-RET', 'gold_special', general: 40_000, premium: null);

        MercadoLibreRetencionPrecio::create([
            'ml_publicacion_producto_id' => $v->id,
            'precio_propuesto' => 40_000,
            'precio_publicado' => 100_000,
            'caida_pct' => 60,
            'lista_precio_id' => $this->general->id,
            'motivo' => MercadoLibreRetencionPrecio::MOTIVO_SUPERA_UMBRAL,
            'umbral_pct' => 20,
            'estado' => MercadoLibreRetencionPrecio::ESTADO_ABIERTA,
        ]);

        $this->respondeCon(['MLA-RET' => 100_000]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar();

        $this->assertSame(0, $r['resumen']['difieren']);
        $this->assertSame(1, $r['resumen']['retenidas']);
    }

    /** FR-024: "no pude verificar" y "está bien" no son lo mismo. */
    public function test_una_publicacion_que_no_responde_no_cuenta_como_coincidente(): void
    {
        $this->publicacion('MLA-CAIDA', 'gold_special', general: 100_000, premium: null);
        Http::fake(['api.mercadolibre.com/*' => Http::response(['message' => 'not found'], 404)]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar();

        $this->assertSame(0, $r['resumen']['coinciden']);
        $this->assertSame(1, $r['resumen']['no_verificables']);
    }

    /** US4: las dos configuraciones que publican barato en silencio. */
    public function test_advierte_premium_sin_precio_en_su_lista_y_vinculos_sin_tipo(): void
    {
        $this->publicacion('MLA-SIN-PREMIUM', 'gold_pro', general: 100_000, premium: null);
        $this->publicacion('MLA-SIN-TIPO', 'gold_special', general: 100_000, premium: null)
            ->update(['listing_type_id' => null]);

        $this->respondeCon(['MLA-SIN-PREMIUM' => 100_000, 'MLA-SIN-TIPO' => 100_000]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar();

        $this->assertCount(1, $r['advertencias']['premium_sin_precio_en_su_lista']);
        $this->assertCount(1, $r['advertencias']['sin_tipo_de_publicacion']);
    }

    /**
     * Una publicación en promoción NO está desfasada: Mercado Libre cobra menos pero el precio de
     * lista sigue siendo el del CRM. Caso real del 27/08/2026 — un 5% de descuento hizo que el
     * chequeo la reportara como diferencia el mismo día de su estreno.
     */
    public function test_una_promocion_no_se_reporta_como_desfasaje(): void
    {
        $this->publicacion('MLA-PROMO', 'gold_special', general: 34_409.77, premium: null);

        Http::fake([
            'api.mercadolibre.com/items/MLA-PROMO*' => Http::response([
                'id' => 'MLA-PROMO',
                'price' => 32_689.28,
                'original_price' => 34_409.77,
                'status' => 'active',
            ], 200),
        ]);

        $r = app(ChequeoPreciosPublicados::class)->ejecutar(refrescarPublicado: true);

        $this->assertSame(0, $r['resumen']['difieren'], 'El precio de lista coincide: no hay desfasaje.');
        $this->assertSame(1, $r['resumen']['en_promocion']);
        $this->assertEqualsWithDelta(5.00, $r['promociones'][0]['descuento_pct'], 0.01);

        $this->assertEqualsWithDelta(
            34_409.77,
            (float) MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA-PROMO')->value('precio_publicado'),
            0.01,
            'La referencia del corte es el precio de LISTA: guardar el promocional dejaría un piso '.
            'artificialmente bajo contra el que medir la próxima bajada.',
        );
    }

    /** El backfill previo a activar el corte (Decisión 5). */
    public function test_refrescar_publicado_puebla_la_referencia_del_corte(): void
    {
        $v = $this->publicacion('MLA-BACKFILL', 'gold_special', general: 100_000, premium: null);
        $this->assertNull($v->precio_publicado);

        $this->respondeCon(['MLA-BACKFILL' => 100_000]);

        app(ChequeoPreciosPublicados::class)->ejecutar(refrescarPublicado: true);

        $this->assertEqualsWithDelta(100_000, (float) $v->fresh()->precio_publicado, 0.01);
        $this->assertNotNull($v->fresh()->precio_publicado_en);
    }
}
