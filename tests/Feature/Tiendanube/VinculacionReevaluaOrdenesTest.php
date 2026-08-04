<?php

namespace Tests\Feature\Tiendanube;

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
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 041, US1 (MVP): mismos casos que
 * `Tests\Feature\MercadoLibre\VinculacionReevaluaOrdenesTest` mapeados al
 * canal TiendaNube (T008), vía `TiendanubeVarianteProductoObserver`.
 */
class VinculacionReevaluaOrdenesTest extends TestCase
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

    private function crearOrdenConItem(int $variantId, array $overridesOrden = []): TiendanubeOrden
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

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => $variantId, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ]);

        return $orden;
    }

    public function test_crear_la_vinculacion_deja_lista_la_orden_pendiente(): void
    {
        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }

    public function test_crear_la_vinculacion_con_creacion_automatica_convierte_la_orden(): void
    {
        TiendanubeConexionRest::actual()->update(['creacion_automatica' => true]);

        $orden = $this->crearOrdenConItem(1);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
        $this->assertNotNull($orden->fresh()->venta_id);
    }

    public function test_editar_una_vinculacion_existente_reevalua_la_orden(): void
    {
        $productoViejo = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => false]);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $productoViejo->id]);
        $orden = $this->crearOrdenConItem(1, ['motivo' => 'producto_inexistente']);

        $productoNuevo = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $vinculo->update(['producto_id' => $productoNuevo->id]);

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }

    public function test_eliminar_una_vinculacion_vuelve_a_requiere_atencion_una_orden_que_estaba_lista(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);
        $orden = $this->crearOrdenConItem(1, ['estado_conversion' => 'lista', 'motivo' => null]);

        $vinculo->delete();

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('variante_sin_vincular', $orden->motivo->value);
    }

    public function test_una_orden_con_venta_id_seteado_no_se_toca(): void
    {
        $venta = Venta::factory()->create();
        $orden = $this->crearOrdenConItem(1, [
            'venta_id' => $venta->id, 'estado_conversion' => 'convertida', 'motivo' => null,
        ]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
        $this->assertSame($venta->id, $orden->fresh()->venta_id);
    }

    public function test_una_orden_de_otra_variante_no_relacionada_no_se_toca(): void
    {
        $orden = $this->crearOrdenConItem(2);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);

        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);
        $this->assertSame('variante_sin_vincular', $orden->fresh()->motivo->value);
    }
}
