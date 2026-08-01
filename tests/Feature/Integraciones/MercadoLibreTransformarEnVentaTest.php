<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Enums\MercadoLibre\EstadoConversion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 025, US1/US2/US3: botón "Transformar todas en Venta" — conversión en
 * lote síncrona de todas las órdenes "Lista" de Mercado Libre, guardrails
 * compartidos con la sincronización, resumen con detalle de fallos y
 * no-duplicación ante carrera con la creación automática.
 */
class MercadoLibreTransformarEnVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    private function crearOrden(array $overrides = []): MercadoLibreOrden
    {
        $itemId = 'MLA'.random_int(1, 999999);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => $itemId], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ], $overrides));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $itemId, 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    // -------------------------------------------------------------------
    // US1 — conversión en lote
    // -------------------------------------------------------------------

    public function test_lote_con_varias_ordenes_listas_convierte_todas(): void
    {
        $this->crearOrden();
        $this->crearOrden();
        $this->crearOrden();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJson([
            'ok' => true, 'total' => 3, 'convertidas' => 3, 'fallidas' => 0,
        ]);
        $this->assertSame(3, MercadoLibreOrden::where('estado_conversion', EstadoConversion::Convertida->value)->count());
        $this->assertDatabaseCount('ventas', 3);
        foreach (MercadoLibreOrden::all() as $orden) {
            $this->assertSame(auth()->id(), $orden->convertida_por);
        }
    }

    public function test_funcion_avanzada_desactivada_bloquea_el_lote_sin_tocar_ordenes(): void
    {
        $this->crearOrden();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame(1, MercadoLibreOrden::where('estado_conversion', EstadoConversion::Lista->value)->count());
    }

    public function test_modo_solo_lectura_bloquea_el_lote_sin_tocar_ordenes(): void
    {
        $this->crearOrden();
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame(1, MercadoLibreOrden::where('estado_conversion', EstadoConversion::Lista->value)->count());
    }

    public function test_sin_ordenes_listas_responde_ceros_sin_error(): void
    {
        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJson([
            'ok' => true, 'total' => 0, 'convertidas' => 0, 'fallidas' => 0,
        ]);
    }

    public function test_ordenes_en_otros_estados_quedan_intactas_y_fuera_del_conteo(): void
    {
        $this->crearOrden();
        $pendiente = $this->crearOrden(['estado_conversion' => EstadoConversion::PendientePago->value]);
        $convertida = $this->crearOrden(['estado_conversion' => EstadoConversion::Convertida->value]);
        $cancelada = $this->crearOrden(['estado_conversion' => EstadoConversion::Cancelada->value]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(EstadoConversion::PendientePago, $pendiente->fresh()->estado_conversion);
        $this->assertSame(EstadoConversion::Convertida, $convertida->fresh()->estado_conversion);
        $this->assertSame(EstadoConversion::Cancelada, $cancelada->fresh()->estado_conversion);
    }

    // -------------------------------------------------------------------
    // US2 — detalle de fallos
    // -------------------------------------------------------------------

    public function test_orden_con_cliente_ambiguo_queda_en_el_detalle_de_fallidas_sin_afectar_el_resto(): void
    {
        $this->crearOrden();

        Cliente::factory()->create(['apodo_ml' => 'DUP']);
        Cliente::factory()->create(['apodo_ml' => 'DUP']);
        $this->crearOrden(['comprador_apodo' => 'DUP', 'comprador_ml_id' => '999999999']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJsonPath('total', 2)->assertJsonPath('convertidas', 1)->assertJsonPath('fallidas', 1);
        $detalle = $respuesta->json('detalle_fallidas');
        $this->assertCount(1, $detalle);
        $this->assertSame('Más de un Cliente con el mismo apodo de Mercado Libre', $detalle[0]['motivo']);
    }

    // -------------------------------------------------------------------
    // US3 — "forzar ya" con modo automático activo + no-duplicación
    // -------------------------------------------------------------------

    public function test_procesa_el_lote_igual_con_creacion_automatica_activa(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);
        $this->crearOrden();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJsonPath('convertidas', 1);
    }

    public function test_no_duplica_la_venta_si_la_orden_ya_fue_convertida_por_la_creacion_automatica(): void
    {
        $orden = $this->crearOrden();

        // Simula la carrera: la creación automática ya convirtió la orden antes
        // de que corra el batch manual sobre la misma orden en estado "Lista".
        app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $orden->refresh();
        $orden->forceFill(['estado_conversion' => EstadoConversion::Lista->value])->save();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk();
        $this->assertDatabaseCount('ventas', 1);
    }
}
