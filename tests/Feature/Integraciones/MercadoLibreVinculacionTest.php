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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 012): vinculación 1:1 publicación↔producto. FR-022/FR-026/FR-027,
 * SC-006/SC-007.
 *
 * Incluye la validación contra la API real agregada el 28/07/2026: la
 * publicación tiene que existir y pertenecer a la cuenta conectada. Sin eso se
 * podía vincular un ID inventado —o de otra cuenta— y la vinculación nunca iba
 * a matchear ninguna orden, sin ningún aviso.
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

    /**
     * OJO: `Http::fake()` ACUMULA stubs, no los reemplaza, y gana el primero que
     * matchea. Por eso el fake NO va en setUp(): si estuviera ahí, taparía al de
     * cada test y los casos de error nunca se ejercitarían (pasó al escribir
     * estos tests: daban 201 en vez de 422 sin que se notara el motivo).
     */
    private function fakearPublicacion(?int $sellerId = self::ML_USER_ID, int $codigo = 200): void
    {
        $cuerpo = $codigo === 200
            ? ['id' => 'MLA-CUALQUIERA', 'seller_id' => $sellerId, 'title' => 'Producto']
            : ['message' => 'Item not found'];

        Http::fake(['api.mercadolibre.com/items/*' => Http::response($cuerpo, $codigo)]);
    }

    public function test_vincula_publicacion_con_producto(): void
    {
        $this->fakearPublicacion();
        $producto = Producto::factory()->create();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA1927008393',
            'producto_id' => $producto->id,
            'titulo_ml' => 'Producto de prueba',
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ml_publicacion_producto', [
            'ml_item_id' => 'MLA1927008393',
            'producto_id' => $producto->id,
        ]);
    }

    public function test_rechaza_una_publicacion_que_no_existe_en_mercado_libre(): void
    {
        $this->fakearPublicacion(codigo: 404);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA9999999999',
            'producto_id' => Producto::factory()->create()->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('ml_item_id');
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_rechaza_una_publicacion_de_otra_cuenta_de_mercado_libre(): void
    {
        $this->fakearPublicacion(sellerId: 999999999);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA1111111111',
            'producto_id' => Producto::factory()->create()->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('ml_item_id');
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_rechaza_vincular_la_misma_publicacion_a_un_segundo_producto(): void
    {
        $this->fakearPublicacion();
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $productoA->id]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA111',
            'producto_id' => $productoB->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('ml_item_id');
    }

    public function test_rechaza_vincular_un_producto_ya_vinculado_a_otra_publicacion(): void
    {
        $this->fakearPublicacion();
        $producto = Producto::factory()->create();

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $producto->id]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA222',
            'producto_id' => $producto->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('producto_id');
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

    public function test_rechaza_publicaciones_con_variantes(): void
    {
        $this->fakearPublicacion();
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '9001', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '1', 'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA-VAR', 'ml_variation_id' => '555',
            'titulo' => 'Con variante', 'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100,
        ]);

        $producto = Producto::factory()->create();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.store'), [
            'ml_item_id' => 'MLA-VAR',
            'producto_id' => $producto->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('ml_item_id');
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
