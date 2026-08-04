<?php

namespace Tests\Unit\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Tiendanube\ReevaluadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 041, Foundational: mismos casos que
 * `Tests\Unit\Services\MercadoLibre\ReevaluadorOrdenesTest` mapeados al canal
 * TiendaNube (T005).
 *
 * `TiendanubeVarianteProducto::create()` se crea siempre con `withoutEvents()`
 * por el mismo motivo que en el equivalente ML: aislar `reevaluarUna()`/
 * `reevaluarAfectadasPorVariante()` del `TiendanubeVarianteProductoObserver`,
 * que se prueba aparte en `tests/Feature/Tiendanube/VinculacionReevaluaOrdenesTest`.
 */
class ReevaluadorOrdenesTest extends TestCase
{
    use RefreshDatabase;

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
        $cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuentaTesoreria->id]);
    }

    private function crearOrdenConItem(int $variantId, array $overridesOrden = [], array $overridesItem = []): TiendanubeOrden
    {
        $orden = TiendanubeOrden::create(array_replace([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'requiere_atencion',
            'motivo' => 'variante_sin_vincular',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ], $overridesOrden));

        TiendanubeOrdenItem::create(array_replace([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => $variantId, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ], $overridesItem));

        return $orden->fresh(['items']);
    }

    private function ventaId(): int
    {
        return Venta::factory()->create()->id;
    }

    private function crearVinculo(int $variantId, int $productoId): TiendanubeVarianteProducto
    {
        return TiendanubeVarianteProducto::withoutEvents(
            fn () => TiendanubeVarianteProducto::create(['variant_id' => $variantId, 'producto_id' => $productoId])
        );
    }

    public function test_no_op_si_la_orden_ya_tiene_venta_id(): void
    {
        $orden = $this->crearOrdenConItem(1, ['venta_id' => $this->ventaId(), 'estado_conversion' => 'convertida', 'motivo' => null]);
        $estadoPrevio = $orden->estado_conversion->value;

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $this->assertSame($estadoPrevio, $orden->fresh()->estado_conversion->value);
    }

    public function test_pasa_a_lista_y_limpia_motivo_cuando_se_vincula_la_variante(): void
    {
        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo(1, $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('lista', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
        $this->assertNull($orden->motivo_detalle);
    }

    public function test_dispara_creacion_automatica_cuando_queda_lista_y_la_config_lo_permite(): void
    {
        TiendanubeConexionRest::actual()->update(['creacion_automatica' => true]);

        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo(1, $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('convertida', $orden->estado_conversion->value);
        $this->assertNotNull($orden->venta_id);
    }

    public function test_no_dispara_creacion_automatica_si_la_config_esta_apagada(): void
    {
        TiendanubeConexionRest::actual()->update(['creacion_automatica' => false]);

        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo(1, $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('lista', $orden->estado_conversion->value);
        $this->assertNull($orden->venta_id);
    }

    public function test_error_en_conversion_deja_la_orden_requiere_atencion_con_detalle_sin_relanzar(): void
    {
        TiendanubeConexionRest::actual()->update(['creacion_automatica' => true]);

        // Sin depósito para que ConversorOrdenAVenta falle de forma controlada: se
        // fuerza el error borrando el único depósito creado en setUp.
        Deposito::query()->delete();

        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo(1, $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('error_conversion', $orden->motivo->value);
        $this->assertNotNull($orden->motivo_detalle);
        $this->assertNull($orden->venta_id);
    }

    public function test_reevaluar_afectadas_por_variante_solo_trae_ordenes_de_la_variante_correcta_no_convertidas(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo(1, $producto->id);

        $afectada = $this->crearOrdenConItem(1);
        $otraVariante = $this->crearOrdenConItem(2);
        $yaConvertida = $this->crearOrdenConItem(1, ['venta_id' => $this->ventaId(), 'estado_conversion' => 'convertida', 'motivo' => null]);
        $cancelada = $this->crearOrdenConItem(1, ['estado_conversion' => 'cancelada', 'status' => 'cancelled', 'motivo' => null]);
        $pendientePago = $this->crearOrdenConItem(1, ['estado_conversion' => 'pendiente_pago', 'payment_status' => 'pending', 'motivo' => null]);

        $cantidad = app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorVariante('1');

        $this->assertSame(1, $cantidad);
        $this->assertSame('lista', $afectada->fresh()->estado_conversion->value);
        $this->assertSame('requiere_atencion', $otraVariante->fresh()->estado_conversion->value);
        $this->assertSame('convertida', $yaConvertida->fresh()->estado_conversion->value);
        $this->assertSame('cancelada', $cancelada->fresh()->estado_conversion->value);
        $this->assertSame('pendiente_pago', $pendientePago->fresh()->estado_conversion->value);
    }
}
