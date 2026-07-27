<?php

namespace Tests\Feature\Integraciones;

use App\Models\Deposito;
use App\Models\Integracion;
use App\Models\IntegracionEvento;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\ProductoCanal;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\User;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNube;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNubeFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 — Sync bidireccional TiendaNube: push CRM→TN, aplicación TN→CRM, dedupe (SC-004/SC-006, D8). */
class SyncBidireccionalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private Integracion $integracion;

    private Producto $producto;

    private ListaPrecio $lista;

    private Deposito $deposito;

    private ProductoCanal $mapeo;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        $this->lista = ListaPrecio::create(['nombre' => 'Lista de precios TiendaNube', 'activo' => true]);
        $this->deposito = Deposito::create(['nombre' => 'Depósito TiendaNube', 'activo' => true]);

        $this->integracion = Integracion::create([
            'canal' => 'tiendanube',
            'credenciales' => ['access_token' => 'x', 'refresh_token' => 'y', 'expires_at' => now()->addDays(30), 'cuenta_id' => '1'],
            'config' => ['lista_precio_id' => $this->lista->id, 'deposito_id' => $this->deposito->id],
            'estado' => 'conectado',
            'activo' => true,
        ]);

        $this->producto = Producto::factory()->create(['codigo' => 'SKU-BIDI', 'activo' => true]);
        $this->mapeo = ProductoCanal::create([
            'integracion_id' => $this->integracion->id,
            'producto_id' => $this->producto->id,
            'id_externo' => 'EXT-BIDI',
            'sku_externo' => 'SKU-BIDI',
            'sincronizable' => true,
        ]);
    }

    /** @return ClienteTiendaNubeFake */
    private function fakeTn()
    {
        return app(ClienteTiendaNube::class);
    }

    public function test_cambio_de_precio_o_stock_en_crm_se_empuja_a_tiendanube(): void
    {
        PrecioProducto::create(['producto_id' => $this->producto->id, 'lista_precio_id' => $this->lista->id, 'precio' => 199.90]);
        Stock::create(['producto_id' => $this->producto->id, 'variante_id' => null, 'deposito_id' => $this->deposito->id, 'cantidad' => 15]);

        $fake = $this->fakeTn();
        $this->assertNotEmpty($fake->actualizacionesPush);

        $ultimo = end($fake->actualizacionesPush);
        $this->assertSame($this->mapeo->id, $ultimo['mapeo_id']);
        $this->assertEquals(199.90, $ultimo['precio']);
        $this->assertEquals(15, $ultimo['stock']);
    }

    public function test_webhook_stock_actualizado_aplica_sobre_el_deposito_tiendanube(): void
    {
        $respuesta = $this->postJson(route('integraciones.webhook.tn'), [
            'evento' => 'stock_actualizado',
            'id_externo' => 'EXT-BIDI',
            'stock' => 42,
        ]);
        $respuesta->assertOk()->assertJson(['ok' => true]);

        $stock = Stock::where('producto_id', $this->producto->id)->where('deposito_id', $this->deposito->id)->first();
        $this->assertEquals(42, (float) $stock->cantidad);

        $evento = IntegracionEvento::where('tipo', 'stock_actualizado')->where('id_externo', 'EXT-BIDI')->first();
        $this->assertSame('procesado', $evento->estado);
    }

    public function test_webhook_precio_actualizado_aplica_sobre_la_lista_tiendanube(): void
    {
        $this->postJson(route('integraciones.webhook.tn'), [
            'evento' => 'precio_actualizado',
            'id_externo' => 'EXT-BIDI',
            'precio' => 555.55,
        ])->assertOk();

        $precio = PrecioProducto::where('producto_id', $this->producto->id)->where('lista_precio_id', $this->lista->id)->first();
        $this->assertEquals(555.55, (float) $precio->precio);
    }

    public function test_evento_duplicado_no_se_aplica_dos_veces(): void
    {
        $payload = ['evento' => 'stock_actualizado', 'id_externo' => 'EXT-BIDI', 'stock' => 7];

        $this->postJson(route('integraciones.webhook.tn'), $payload)->assertOk();
        $this->postJson(route('integraciones.webhook.tn'), $payload)->assertOk();

        $this->assertSame(1, IntegracionEvento::where('tipo', 'stock_actualizado')->where('id_externo', 'EXT-BIDI')->count());

        $stock = Stock::where('producto_id', $this->producto->id)->where('deposito_id', $this->deposito->id)->first();
        $this->assertEquals(7, (float) $stock->cantidad);
    }

    public function test_evento_para_item_no_mapeado_se_ignora_con_motivo(): void
    {
        $this->postJson(route('integraciones.webhook.tn'), [
            'evento' => 'stock_actualizado',
            'id_externo' => 'NO-MAPEADO',
            'stock' => 3,
        ])->assertOk();

        $evento = IntegracionEvento::where('tipo', 'stock_actualizado')->where('id_externo', 'NO-MAPEADO')->first();
        $this->assertSame('ignorado', $evento->estado);
        $this->assertNotEmpty($evento->detalle);
    }
}
