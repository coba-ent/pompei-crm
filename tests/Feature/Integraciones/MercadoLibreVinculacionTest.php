<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US2 (spec 012): vinculación 1:1 publicación↔producto. FR-022/FR-026,
 * SC-006/SC-007. El alta manual (`store()`) se reemplazó por la vinculación
 * automática (spec 021 reemplazo, ver MercadoLibreVinculacionAutomaticaTest);
 * lo que queda acá es la garantía de cardinalidad 1:1 y la baja.
 */
class MercadoLibreVinculacionTest extends TestCase
{
    use RefreshDatabase;

    private const ML_USER_ID = 3554577007;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => self::ML_USER_ID, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

    }

    public function test_la_cardinalidad_1a1_se_garantiza_a_nivel_de_base_de_datos(): void
    {
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $productoA->id]);

        $this->expectException(QueryException::class);

        // Bypassea la validación del FormRequest a propósito: la garantía real es el índice único.
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $productoB->id]);
    }

    public function test_eliminar_vinculacion_con_ordenes_convertidas_advierte_y_no_modifica_ventas(): void
    {
        $producto = Producto::factory()->create();
        $vinculacion = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $producto->id]);

        $venta = \App\Models\Venta::factory()->create();
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '9002', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'convertida', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '1', 'sincronizada_en' => now(), 'venta_id' => $venta->id,
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA111', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100, 'producto_id' => $producto->id,
        ]);

        $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.destroy', $vinculacion));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($respuesta->json('advertencia'));
        $this->assertDatabaseMissing('ml_publicacion_producto', ['id' => $vinculacion->id]);
        // La Venta ya creada no se modifica.
        $this->assertDatabaseHas('ventas', ['id' => $venta->id]);
    }
}
