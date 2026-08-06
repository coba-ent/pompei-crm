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
 * US3 (spec 012): derivación del comprobante (T064/T064a), idempotencia
 * (T065), cobranza y stock (T067), resolución de Cliente (T068), y
 * corrección manual del comprobante tras la conversión (T051a, FR-043).
 */
class MercadoLibreConversionTest extends TestCase
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
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => 'MLA1'], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ], $overrides));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    // -------------------------------------------------------------------
    // T064 — derivación del comprobante
    // -------------------------------------------------------------------

    public function test_responsable_inscripto_deriva_factura_a(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => 'IVA Responsable Inscripto']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }

    public function test_monotributo_con_cuit_deriva_factura_b_no_a(): void
    {
        // El caso que la regla de Mercado Libire ("CUIT → A") resolvería mal.
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => 'Monotributo',
            'comprador_doc_tipo' => 'CUIT',
            'comprador_doc_numero' => '20304050607',
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_iva_exento_deriva_factura_b(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => 'IVA Exento']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_consumidor_final_deriva_factura_b(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => 'Consumidor Final']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_sin_dato_fiscal_deriva_b_y_persiste_consumidor_final(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => null, 'comprador_doc_tipo' => null]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $cliente = $resultado['venta']->cliente;
        $this->assertSame('Consumidor Final', $cliente->condicionIva->nombre);
    }

    // -------------------------------------------------------------------
    // Spec 014 — un CUIT/CUIL matemáticamente inválido de Mercado Libre no
    // se usa ni para derivar el comprobante ni para persistirse en el Cliente.
    // -------------------------------------------------------------------

    public function test_cuit_invalido_sin_condicion_iva_deriva_como_si_no_hubiera_documento(): void
    {
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => null,
            'comprador_doc_tipo' => 'CUIT',
            'comprador_doc_numero' => '20304050600', // dígito verificador incorrecto.
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertSame(EstadoConversion::Convertida->value, $orden->fresh()->estado_conversion->value);
    }

    public function test_cuit_invalido_sin_condicion_iva_el_cliente_nuevo_queda_sin_cuit(): void
    {
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => null,
            'comprador_doc_tipo' => 'CUIT',
            'comprador_doc_numero' => '20304050600',
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertNull($resultado['venta']->cliente->cuit);
    }

    public function test_cuil_invalido_sin_condicion_iva_se_comporta_igual_que_cuit(): void
    {
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => null,
            'comprador_doc_tipo' => 'CUIL',
            'comprador_doc_numero' => '20304050600',
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertNull($resultado['venta']->cliente->cuit);
    }

    /** No regresión: un CUIT válido sigue aproximando a Factura A como hoy. */
    public function test_cuit_valido_sin_condicion_iva_no_cambia_el_comportamiento_actual(): void
    {
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => null,
            'comprador_doc_tipo' => 'CUIT',
            'comprador_doc_numero' => '20123456786', // dígito verificador correcto.
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
        $this->assertSame('20123456786', $resultado['venta']->cliente->cuit);
    }

    /** CHK004 / FR-007 incondicional: aplica también cuando el comprobante se deriva de la condición de IVA. */
    public function test_documento_invalido_no_se_persiste_aunque_la_condicion_de_iva_haya_sido_informada(): void
    {
        $orden = $this->crearOrden([
            'comprador_condicion_iva' => 'Consumidor Final',
            'comprador_doc_tipo' => 'CUIT',
            'comprador_doc_numero' => '20304050600',
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertNull($resultado['venta']->cliente->cuit);
    }

    /** T051a — el tipo de comprobante es editable en la Venta ya creada (FR-043). */
    public function test_el_tipo_de_comprobante_es_editable_despues_de_la_conversion(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => 'Consumidor Final']);
        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $venta = $resultado['venta'];

        $venta->load('items');
        $itemsPayload = $venta->items->map(fn ($i) => [
            'producto_id' => $i->producto_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad,
            'precio_unitario' => (float) $i->precio_unitario, 'iva_pct' => $i->iva_pct,
        ])->all();

        $respuesta = $this->putJson(route('ventas.update', $venta), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $venta->cliente_id,
            'deposito_id' => Deposito::first()->id,
            'fecha_emision' => $venta->fecha_emision->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => $itemsPayload,
        ]);

        $respuesta->assertOk();
        $this->assertSame('A', $venta->fresh()->tipo_comprobante);
    }

    // -------------------------------------------------------------------
    // T064a — bloqueo por alerta de fraude
    // -------------------------------------------------------------------

    public function test_orden_con_alerta_de_fraude_no_se_convierte(): void
    {
        $orden = $this->crearOrden(['tiene_alerta_fraude' => true]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame('alerta_fraude', $orden->fresh()->motivo->value);
    }

    // -------------------------------------------------------------------
    // T065 — idempotencia
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
    // T067 — cobranza y stock
    // -------------------------------------------------------------------

    public function test_la_venta_queda_cobrada_contra_mercado_pago_y_el_stock_baja(): void
    {
        $orden = $this->crearOrden();

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $venta = $resultado['venta'];

        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());
        $this->assertDatabaseHas('cobros', ['venta_id' => $venta->id]);
        $this->assertDatabaseHas('movimientos_tesoreria', ['cuenta_tesoreria_id' => CuentaTesoreria::where('nombre', 'Mercado Pago')->value('id')]);
        $this->assertDatabaseHas('stocks', ['cantidad' => -1]);
    }

    // -------------------------------------------------------------------
    // T068 — resolución de Cliente
    // -------------------------------------------------------------------

    public function test_alta_automatica_de_cliente_cuando_no_existe(): void
    {
        $orden = $this->crearOrden(['comprador_apodo' => 'NUEVO_COMPRADOR']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok']);
        $this->assertDatabaseHas('clientes', ['apodo_ml' => 'NUEVO_COMPRADOR', 'ml_user_id' => $orden->comprador_ml_id]);
    }

    public function test_reutiliza_cliente_existente_por_apodo(): void
    {
        $cliente = Cliente::factory()->create(['apodo_ml' => 'YA_EXISTE']);
        $orden = $this->crearOrden(['comprador_apodo' => 'YA_EXISTE']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok']);
        $this->assertSame($cliente->id, $resultado['venta']->cliente_id);
        $this->assertSame($orden->comprador_ml_id, $cliente->fresh()->ml_user_id);
        $this->assertDatabaseCount('clientes', 1);
    }

    // -------------------------------------------------------------------
    // Flujo HTTP completo (rutas + FormRequest + controlador)
    // -------------------------------------------------------------------

    public function test_el_formulario_de_conversion_renderiza_y_el_submit_crea_la_venta(): void
    {
        $orden = $this->crearOrden(['comprador_condicion_iva' => 'Consumidor Final']);

        $this->get(route('ingresos.mercadolibre.convertir', $orden))->assertOk();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true);
        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
    }

    public function test_cliente_ambiguo_bloquea_sin_elegir_al_azar(): void
    {
        Cliente::factory()->create(['apodo_ml' => 'DUPLICADO']);
        Cliente::factory()->create(['apodo_ml' => 'DUPLICADO']);
        $orden = $this->crearOrden(['comprador_apodo' => 'DUPLICADO']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('cliente_ambiguo', $orden->fresh()->motivo->value);
        $this->assertDatabaseCount('ventas', 0);
    }
}
