<?php

namespace Tests\Feature\Integraciones;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US2 (spec 017): vinculación 1:1 variante↔producto. FR-022/FR-026,
 * SC-006/SC-007. El alta manual (`store`) y el selector de pendientes
 * (`tn_orden_items`) se retiraron en spec 024 — reemplazados por
 * `VinculadorAutomatico` (ver TiendanubeVinculacionAutomaticaTest). Este test
 * cubre lo que sigue vigente: edición, baja y la garantía real de
 * cardinalidad a nivel de base de datos.
 */
class TiendanubeVinculacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
    }

    public function test_edita_el_producto_y_nombre_de_una_vinculacion_existente(): void
    {
        $productoOriginal = Producto::factory()->create();
        $productoNuevo = Producto::factory()->create();
        $vinculacion = TiendanubeVarianteProducto::create([
            'variant_id' => 111, 'tn_product_id' => '987654321', 'producto_id' => $productoOriginal->id,
        ]);

        $respuesta = $this->patchJson(route('ingresos.tiendanube.vinculaciones.update', $vinculacion), [
            'variant_id' => 111,
            'tn_product_id' => '987654321',
            'producto_id' => $productoNuevo->id,
            'nombre_variante_tn' => 'Remera — Talle M',
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('tn_variante_producto', [
            'id' => $vinculacion->id, 'producto_id' => $productoNuevo->id, 'nombre_variante_tn' => 'Remera — Talle M',
        ]);
    }

    public function test_la_cardinalidad_1a1_se_garantiza_a_nivel_de_base_de_datos(): void
    {
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        TiendanubeVarianteProducto::create(['variant_id' => 111, 'producto_id' => $productoA->id]);

        $this->expectException(QueryException::class);

        // Bypassea la validación del FormRequest a propósito: la garantía real es el índice único.
        TiendanubeVarianteProducto::create(['variant_id' => 111, 'producto_id' => $productoB->id]);
    }

    public function test_eliminar_vinculacion_con_ordenes_convertidas_advierte_y_no_modifica_ventas(): void
    {
        $producto = Producto::factory()->create();
        $vinculacion = TiendanubeVarianteProducto::create(['variant_id' => 111, 'producto_id' => $producto->id]);

        $venta = Venta::factory()->create();
        $orden = TiendanubeOrden::create([
            'tn_order_id' => 9002, 'status' => 'closed', 'payment_status' => 'paid',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'sincronizada_en' => now(), 'venta_id' => $venta->id,
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 111, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100, 'producto_id' => $producto->id,
        ]);

        $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.destroy', $vinculacion));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($respuesta->json('advertencia'));
        $this->assertDatabaseMissing('tn_variante_producto', ['id' => $vinculacion->id]);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id]);
    }
}
