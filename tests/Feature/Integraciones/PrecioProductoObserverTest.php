<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 016, FR-004/FR-005/FR-006): qué cambios de precio disparan el
 * envío hacia Mercado Libre, sin importar el camino de escritura sobre
 * `precios_producto` (modal de Producto vs. importación masiva).
 */
class PrecioProductoObserverTest extends TestCase
{
    use RefreshDatabase;

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
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        Http::fake(['api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200)]);
    }

    public function test_cambio_de_precio_en_lista_configurada_de_producto_vinculado_dispara_el_envio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 999.5);
        $this->assertTrue($vinculo->fresh()->precio_sincronizado_en !== null);
        $this->assertFalse($vinculo->fresh()->precio_pendiente);
    }

    public function test_cambio_de_precio_de_producto_sin_vinculo_no_dispara_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    public function test_cambio_de_precio_en_lista_distinta_a_la_configurada_no_dispara_nada(): void
    {
        $listaConfigurada = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        $otraLista = ListaPrecio::create(['nombre' => 'Otra Lista', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $listaConfigurada->id]);

        $producto = Producto::factory()->create();
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $otraLista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    public function test_sin_ninguna_lista_de_precios_configurada_ningun_cambio_dispara_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);

        $producto = Producto::factory()->create();
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    public function test_precio_creado_via_camino_de_importacion_masiva_dispara_el_mismo_envio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        // Mismo camino que ImportadorFilas::crearProducto() (spec de importación):
        // $producto->precios()->create([...]) en vez de updateOrCreate().
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 555.00]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 555.0);
        $this->assertTrue($vinculo->fresh()->precio_sincronizado_en !== null);
    }
}
