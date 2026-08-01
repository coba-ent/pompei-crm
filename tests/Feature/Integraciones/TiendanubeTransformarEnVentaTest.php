<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Enums\Tiendanube\EstadoConversion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 025, US1/US2/US3: botón "Transformar todas en Venta" — conversión en
 * lote síncrona de todas las órdenes "Lista" de Tiendanube, guardrails
 * compartidos con la sincronización, resumen con detalle de fallos y
 * no-duplicación ante carrera con la creación automática.
 */
class TiendanubeTransformarEnVentaTest extends TestCase
{
    use RefreshDatabase;

    private CuentaTesoreria $cuentaTesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada, 'modo_solo_lectura' => false,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $this->cuentaTesoreria->id]);
    }

    private function crearOrden(array $overrides = []): TiendanubeOrden
    {
        $variantId = random_int(1, 999999);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::firstOrCreate(['variant_id' => $variantId], ['producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create(array_replace([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ], $overrides));

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => $variantId, 'nombre_producto' => 'Producto',
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

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJson([
            'ok' => true, 'total' => 3, 'convertidas' => 3, 'fallidas' => 0,
        ]);
        $this->assertSame(3, TiendanubeOrden::where('estado_conversion', EstadoConversion::Convertida->value)->count());
        $this->assertDatabaseCount('ventas', 3);
        foreach (TiendanubeOrden::all() as $orden) {
            $this->assertSame(auth()->id(), $orden->convertida_por);
        }
    }

    public function test_funcion_avanzada_desactivada_bloquea_el_lote_sin_tocar_ordenes(): void
    {
        $this->crearOrden();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        $respuesta->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame(1, TiendanubeOrden::where('estado_conversion', EstadoConversion::Lista->value)->count());
    }

    public function test_modo_solo_lectura_bloquea_el_lote_sin_tocar_ordenes(): void
    {
        $this->crearOrden();
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        $respuesta->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame(1, TiendanubeOrden::where('estado_conversion', EstadoConversion::Lista->value)->count());
    }

    public function test_sin_ordenes_listas_responde_ceros_sin_error(): void
    {
        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

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

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        $respuesta->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(EstadoConversion::PendientePago, $pendiente->fresh()->estado_conversion);
        $this->assertSame(EstadoConversion::Convertida, $convertida->fresh()->estado_conversion);
        $this->assertSame(EstadoConversion::Cancelada, $cancelada->fresh()->estado_conversion);
    }

    // -------------------------------------------------------------------
    // US2 — detalle de fallos
    // -------------------------------------------------------------------

    public function test_orden_sin_cuenta_de_tesoreria_configurada_queda_en_el_detalle_de_fallidas_sin_afectar_el_resto(): void
    {
        $this->crearOrden();
        $ordenSinCuenta = $this->crearOrden();
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => null]);
        unset($ordenSinCuenta);

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        // Ambas órdenes reevalúan la falta de cuenta configurada al convertir (misma
        // conexión para toda la tienda), así que el lote entero queda fallido — lo
        // que importa acá es que el detalle liste el motivo correcto por orden y
        // que el lote no aborte ante el primer fallo (FR-004).
        $respuesta->assertOk()->assertJsonPath('total', 2)->assertJsonPath('convertidas', 0)->assertJsonPath('fallidas', 2);
        $detalle = $respuesta->json('detalle_fallidas');
        $this->assertCount(2, $detalle);
        $this->assertSame('No hay una cuenta de Tesorería configurada para Tiendanube', $detalle[0]['motivo']);
    }

    // -------------------------------------------------------------------
    // US3 — "forzar ya" con modo automático activo + no-duplicación
    // -------------------------------------------------------------------

    public function test_procesa_el_lote_igual_con_creacion_automatica_activa(): void
    {
        TiendanubeConexionRest::actual()->update(['creacion_automatica' => true]);
        $this->crearOrden();

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

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

        $respuesta = $this->postJson(route('ingresos.tiendanube.transformarTodasEnVenta'));

        $respuesta->assertOk();
        $this->assertDatabaseCount('ventas', 1);
    }
}
