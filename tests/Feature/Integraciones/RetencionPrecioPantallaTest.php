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
 * Spec 084 — resolver una retención desde la pantalla, y la confirmación al cambiar de lista.
 *
 * La parte delicada es aprobar: se publica el precio **vigente** de la lista, no el que quedó
 * congelado al retener. Publicar el congelado sería mandar a Mercado Libre un precio sobre el que
 * el negocio ya cambió de opinión — el error opuesto al que la spec quiere evitar.
 */
class RetencionPrecioPantallaTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $lista;

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

    /** Deja una retención abierta de $40.000 sobre una publicación con $100.000 publicados. */
    private function retenida(float $precioLista = 40_000): MercadoLibreRetencionPrecio
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create([
            'producto_id' => $producto->id,
            'lista_precio_id' => $this->lista->id,
            'precio' => $precioLista,
        ]);

        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-RET',
            'producto_id' => $producto->id,
            'listing_type_id' => 'gold_special',
            'precio_publicado' => 100_000,
        ]);

        app(SincronizadorPrecios::class)->enviarUno($vinculo, $precioLista);

        return $vinculo->fresh()->retencionAbierta;
    }

    private function putsDePrecio(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'PUT' && array_key_exists('price', $request->data())) {
                $n++;
            }
        }

        return $n;
    }

    public function test_el_listado_devuelve_las_retenciones_abiertas_con_su_precio_vigente(): void
    {
        $this->retenida();

        $r = $this->getJson(route('ingresos.mercadolibre.retencionesPrecio.index'))->assertOk()->json();

        $this->assertSame(1, $r['recordsTotal']);
        $this->assertSame('MLA-RET', $r['data'][0]['ml_item_id']);
        // assertEquals y no assertSame: un float entero viaja como int en el JSON.
        $this->assertEquals(100000, $r['data'][0]['precio_publicado']);
        $this->assertEquals(40000, $r['data'][0]['precio_vigente_lista']);
        $this->assertEquals(60, $r['data'][0]['caida_pct']);
    }

    public function test_aprobar_publica_el_precio_y_cierra_la_retencion(): void
    {
        $retencion = $this->retenida();
        $antes = $this->putsDePrecio();

        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.aprobar', $retencion))
            ->assertOk()
            ->assertJson(['ok' => true, 'precio_enviado' => 40000.0]);

        $this->assertSame($antes + 1, $this->putsDePrecio(), 'Recién al aprobar sale el PUT.');
        $this->assertSame(MercadoLibreRetencionPrecio::ESTADO_APROBADA, $retencion->fresh()->estado);
        $this->assertNotNull($retencion->fresh()->resuelta_por_id);
    }

    public function test_rechazar_cierra_sin_publicar_nada(): void
    {
        $retencion = $this->retenida();
        $antes = $this->putsDePrecio();

        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.rechazar', $retencion))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame($antes, $this->putsDePrecio(), 'Rechazar no puede publicar nada.');
        $this->assertSame(MercadoLibreRetencionPrecio::ESTADO_RECHAZADA, $retencion->fresh()->estado);
    }

    /** FR-014: si el precio de la lista cambió, aprobar a ciegas publicaría otro número. */
    public function test_aprobar_con_el_precio_cambiado_exige_confirmacion_y_envia_el_vigente(): void
    {
        $retencion = $this->retenida();

        PrecioProducto::where('producto_id', $retencion->publicacion->producto_id)
            ->update(['precio' => 75_000]);

        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.aprobar', $retencion))
            ->assertStatus(422)
            ->assertJson([
                'requiere_confirmacion' => true,
                'precio_propuesto' => 40000.0,
                'precio_vigente_lista' => 75000.0,
            ]);

        $this->assertSame(MercadoLibreRetencionPrecio::ESTADO_ABIERTA, $retencion->fresh()->estado);

        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.aprobar', $retencion),
            ['confirma_precio_distinto' => true])
            ->assertOk()
            ->assertJson(['precio_enviado' => 75000.0]);

        $this->assertEqualsWithDelta(75_000, (float) $retencion->fresh()->precio_enviado, 0.01,
            'Se publica el precio vigente, no el congelado al retener.');
    }

    public function test_resolver_una_retencion_ya_resuelta_devuelve_409(): void
    {
        $retencion = $this->retenida();
        $retencion->update(['estado' => MercadoLibreRetencionPrecio::ESTADO_RECHAZADA]);

        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.aprobar', $retencion))->assertStatus(409);
        $this->postJson(route('ingresos.mercadolibre.retencionesPrecio.rechazar', $retencion))->assertStatus(409);
    }

    /**
     * FR-016: la vía de daño más grande que quedaba abierta. Cambiar la lista republicaba todo al
     * guardar, sin previa ni deshacer.
     */
    public function test_cambiar_la_lista_sin_confirmar_no_guarda_ni_republica(): void
    {
        $otra = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);
        $antes = $this->putsDePrecio();

        $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $otra->id,
        ])
            ->assertStatus(422)
            ->assertJson(['requiere_confirmacion' => true]);

        $this->assertSame($this->lista->id, MercadoLibreConfiguracion::actual()->fresh()->lista_precio_id,
            'La configuración no puede haberse guardado.');
        $this->assertSame($antes, $this->putsDePrecio(), 'Y no puede haberse publicado nada.');
    }

    public function test_guardar_sin_cambiar_de_lista_no_pide_confirmacion(): void
    {
        $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $this->lista->id,
        ])->assertOk();
    }

    public function test_la_previa_informa_el_impacto_sin_aplicar_nada(): void
    {
        // Sin retención: acá lo que se prueba es el cálculo del impacto, no el corte.
        $producto = Producto::factory()->create();
        $otra = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);

        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->lista->id, 'precio' => 100_000]);
        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $otra->id, 'precio' => 50_000]);

        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-PREVIA', 'producto_id' => $producto->id,
            'listing_type_id' => 'gold_special', 'precio_publicado' => 100_000,
        ]);

        $r = $this->postJson(route('configuracion.mercadolibre.ventas.previa'), ['lista_precio_id' => $otra->id])
            ->assertOk()
            ->json();

        $this->assertTrue($r['cambia']['general']);
        $this->assertSame(1, $r['impacto']['bajan']);
        $this->assertSame(1, $r['impacto']['quedarian_retenidas']);
        $this->assertEquals(50, $r['impacto']['caida_maxima']['pct']);

        $this->assertSame($this->lista->id, MercadoLibreConfiguracion::actual()->fresh()->lista_precio_id,
            'La previa no aplica nada.');
    }

    /**
     * El endpoint de estado arma el JSON campo por campo, así que una clave que falte hace que el
     * formulario pierda el valor al recargar: se guarda bien en la base pero la pantalla lo muestra
     * apagado, y parece que el switch no anduviera. Ya había pasado con `deposito_full_id` en la
     * spec 065 y volvió a pasar acá el 28/08/2026.
     */
    public function test_el_estado_devuelve_la_configuracion_del_corte(): void
    {
        MercadoLibreConfiguracion::actual()->update([
            'corte_precios_activo' => true,
            'umbral_caida_precio_pct' => 15,
        ]);

        $this->getJson(route('configuracion.mercadolibre.estado'))
            ->assertOk()
            ->assertJsonPath('configuracion.corte_precios_activo', true)
            ->assertJsonPath('configuracion.umbral_caida_precio_pct', '15.00');
    }

    /** El corte nace apagado (Decisión 5): sin backfill retendría todo el primer día. */
    public function test_con_el_corte_apagado_se_publica_como_antes(): void
    {
        MercadoLibreConfiguracion::actual()->update(['corte_precios_activo' => false]);

        $producto = Producto::factory()->create();
        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->lista->id, 'precio' => 1]);

        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-APAGADO', 'producto_id' => $producto->id,
            'listing_type_id' => 'gold_special', 'precio_publicado' => 100_000,
        ]);

        $this->assertTrue(app(SincronizadorPrecios::class)->enviarUno($vinculo, 1));
        $this->assertNull($vinculo->fresh()->retencionAbierta);
    }
}
