<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Cliente;
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
 * US3 (spec 017): derivación del comprobante por documento (FR-039/FR-040),
 * idempotencia (FR-032), cobranza contra la cuenta configurada (FR-045,
 * research.md R5) y movimiento de stock (FR-046), resolución de Cliente por
 * email (FR-036/FR-037/FR-038), y precondiciones (FR-052).
 */
class TiendanubeConversionTest extends TestCase
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
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $this->cuentaTesoreria->id]);
    }

    private function crearOrden(array $overrides = [], array $customerOverrides = []): TiendanubeOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::firstOrCreate(['variant_id' => 1], ['producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create(array_replace([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ], $overrides));

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    // -------------------------------------------------------------------
    // FR-039/FR-040 — derivación del comprobante
    // -------------------------------------------------------------------

    public function test_documento_de_11_digitos_deriva_factura_a(): void
    {
        $orden = $this->crearOrden(['billing_document_number' => '20304050607']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }

    public function test_documento_de_7_u_8_digitos_deriva_factura_b(): void
    {
        $orden = $this->crearOrden(['billing_document_number' => '30405060']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_sin_documento_deriva_b_y_persiste_consumidor_final(): void
    {
        $orden = $this->crearOrden(['billing_document_number' => null]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertSame('Consumidor Final', $resultado['venta']->cliente->condicionIva->nombre);
    }

    /** FR-039: un Cliente ya cargado con condición de IVA usa esa fuente, no la aproximación por documento. */
    public function test_cliente_ya_cargado_como_monotributista_usa_esa_condicion_no_la_aproximacion(): void
    {
        $cliente = Cliente::factory()->create([
            'email' => 'monotributista@test.com',
            'condicion_iva_id' => \App\Models\CondicionIva::where('nombre', 'Monotributista')->value('id'),
        ]);
        $orden = $this->crearOrden(['comprador_email' => 'monotributista@test.com', 'billing_document_number' => '20304050607']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertSame($cliente->id, $resultado['venta']->cliente_id);
    }

    // -------------------------------------------------------------------
    // FR-032 — idempotencia
    // -------------------------------------------------------------------

    public function test_reintentar_la_conversion_de_una_orden_ya_convertida_no_duplica(): void
    {
        $orden = $this->crearOrden();
        $conversor = app(ConversorOrdenAVenta::class);

        $primero = $conversor->convertir($orden, null, automatica: true);
        $segundo = $conversor->convertir($orden->fresh(), null, automatica: true);

        $this->assertTrue($primero['ok'], json_encode($primero));
        $this->assertFalse($segundo['ok']);
        $this->assertDatabaseCount('ventas', 1);
    }

    // -------------------------------------------------------------------
    // FR-044/FR-045/FR-046 — cobranza y stock
    // -------------------------------------------------------------------

    public function test_la_venta_queda_cobrada_contra_la_cuenta_configurada_y_el_stock_baja(): void
    {
        $orden = $this->crearOrden();

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $venta = $resultado['venta'];

        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());
        $this->assertDatabaseHas('cobros', ['venta_id' => $venta->id]);
        $this->assertDatabaseHas('movimientos_tesoreria', ['cuenta_tesoreria_id' => $this->cuentaTesoreria->id]);
        $this->assertDatabaseHas('stocks', ['cantidad' => -1]);
        $this->assertSame('tiendanube', $venta->fresh()->origen);
    }

    public function test_total_de_la_venta_coincide_exactamente_con_el_de_la_orden(): void
    {
        $orden = $this->crearOrden(['total' => 1210.00]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertSame('1210.00', $resultado['venta']->fresh()->total);
    }

    // -------------------------------------------------------------------
    // FR-036/FR-037/FR-038 — resolución de Cliente
    // -------------------------------------------------------------------

    public function test_alta_automatica_de_cliente_cuando_no_existe(): void
    {
        $orden = $this->crearOrden(['comprador_email' => 'nuevo@test.com', 'tn_customer_id' => 555]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertDatabaseHas('clientes', ['email' => 'nuevo@test.com', 'tn_customer_id' => 555]);
    }

    public function test_reutiliza_cliente_existente_por_email(): void
    {
        $cliente = Cliente::factory()->create(['email' => 'ya-existe@test.com']);
        $orden = $this->crearOrden(['comprador_email' => 'ya-existe@test.com']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame($cliente->id, $resultado['venta']->cliente_id);
        $this->assertSame((string) $orden->tn_customer_id, (string) $cliente->fresh()->tn_customer_id);
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_cliente_ambiguo_bloquea_sin_elegir_al_azar(): void
    {
        Cliente::factory()->create(['email' => 'duplicado@test.com']);
        Cliente::factory()->create(['email' => 'duplicado@test.com']);
        $orden = $this->crearOrden(['comprador_email' => 'duplicado@test.com', 'tn_customer_id' => null]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('cliente_ambiguo', $orden->fresh()->motivo->value);
        $this->assertDatabaseCount('ventas', 0);
    }

    // -------------------------------------------------------------------
    // FR-052 — precondiciones
    // -------------------------------------------------------------------

    public function test_variante_sin_vincular_bloquea_la_conversion(): void
    {
        $productoSinVincular = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 7001, 'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'total' => 100, 'moneda' => 'ARS', 'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 99, 'variant_id' => 999, 'nombre_producto' => 'Sin vincular',
            'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('variante_sin_vincular', $orden->fresh()->motivo->value);
        unset($productoSinVincular);
    }

    public function test_moneda_distinta_bloquea_la_conversion(): void
    {
        $orden = $this->crearOrden(['moneda' => 'USD']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('moneda_invalida', $orden->fresh()->motivo->value);
    }

    public function test_sin_cuenta_de_tesoreria_configurada_bloquea_la_conversion(): void
    {
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => null]);
        $orden = $this->crearOrden();

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('cuenta_tesoreria_no_configurada', $orden->fresh()->motivo->value);
    }

    // -------------------------------------------------------------------
    // Flujo HTTP completo (rutas + FormRequest + controlador)
    // -------------------------------------------------------------------

    public function test_el_formulario_de_conversion_renderiza_y_el_submit_crea_la_venta(): void
    {
        $orden = $this->crearOrden();

        $this->get(route('ingresos.tiendanube.convertir', $orden))->assertOk();

        $respuesta = $this->postJson(route('ingresos.tiendanube.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true);
        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
    }
}
