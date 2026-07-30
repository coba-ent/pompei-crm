<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US6 (spec 018 ampliación, FR-024/FR-025/FR-026): qué cambios de precio
 * disparan el envío hacia Tiendanube, sin importar el camino de escritura
 * sobre `precios_producto` (modal de Producto vs. importación masiva) —
 * calcado del equivalente de Mercado Libre (spec 016).
 */
class TiendanubePrecioProductoObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConfiguracion::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false,
        ]);

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);
    }

    public function test_cambio_de_precio_en_lista_configurada_de_producto_vinculado_dispara_el_envio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertSent(function ($request) {
            $update = $request['params']['arguments']['updates'][0] ?? [];

            return $request['params']['name'] === 'update_stock_and_price' && ($update['price'] ?? null) === 999.5;
        });
        $this->assertNotNull($vinculo->fresh()->precio_sincronizado_en);
        $this->assertFalse($vinculo->fresh()->precio_pendiente);
    }

    public function test_cambio_de_precio_de_producto_sin_vinculo_no_dispara_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    public function test_cambio_de_precio_en_lista_distinta_a_la_configurada_no_dispara_nada(): void
    {
        $listaConfigurada = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        $otraLista = ListaPrecio::create(['nombre' => 'Otra Lista', 'activo' => true]);
        TiendanubeConfiguracion::actual()->update(['lista_precio_id' => $listaConfigurada->id]);

        $producto = Producto::factory()->create();
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $otraLista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    public function test_sin_ninguna_lista_de_precios_configurada_ningun_cambio_dispara_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);

        $producto = Producto::factory()->create();
        TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        Http::assertNothingSent();
    }

    /** FR-025: mismo camino que ImportadorFilas::crearProducto() — create() en vez de updateOrCreate(). */
    public function test_precio_creado_via_camino_de_importacion_masiva_dispara_el_mismo_envio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::factory()->create();
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);

        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 555.00]);

        Http::assertSent(function ($request) {
            $update = $request['params']['arguments']['updates'][0] ?? [];

            return ($update['price'] ?? null) === 555.0;
        });
        $this->assertNotNull($vinculo->fresh()->precio_sincronizado_en);
    }
}
