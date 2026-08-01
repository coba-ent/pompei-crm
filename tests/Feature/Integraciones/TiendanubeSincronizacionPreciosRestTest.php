<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\SincronizadorPrecios;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 024, US2: SincronizadorPrecios vía el cliente REST — `PUT
 * /products/{id}/variants/{id}` con `{"price": ...}`, disparo por evento
 * (PrecioProductoObserver) y manual, mismos cortes de FR-032/FR-033.
 */
class TiendanubeSincronizacionPreciosRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false,
        ]);
    }

    /**
     * Crea el vínculo con la función "tiendanube" temporalmente desactivada:
     * así el intento automático que dispara PrecioProductoObserver al crear
     * el precio queda bloqueado sin llamar a la red (rápido, sin reintentos)
     * y el vínculo queda precio_pendiente=true, listo para la acción manual.
     */
    private function crearVinculoPendienteDePrecio(int $listaId, float $precio = 100): TiendanubeVarianteProducto
    {
        $producto = Producto::factory()->create();
        $vinculo = TiendanubeVarianteProducto::create([
            'variant_id' => $producto->id * 10,
            'tn_product_id' => (string) $producto->id,
            'producto_id' => $producto->id,
        ]);

        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);
        $producto->precios()->create(['lista_precio_id' => $listaId, 'precio' => $precio]);
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        return $vinculo;
    }

    public function test_envia_el_precio_por_put_a_la_variante(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);
        $vinculo = $this->crearVinculoPendienteDePrecio($lista->id, 150);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'actualizados' => 1, 'con_error' => 0]);
        Http::assertSent(function ($request) use ($vinculo) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), "products/{$vinculo->tn_product_id}/variants/{$vinculo->variant_id}")
                && $request['price'] === 150.0;
        });
    }

    public function test_sin_lista_de_precios_configurada_responde_409_con_motivo(): void
    {
        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('Lista de Precios', $respuesta->json('mensaje'));
    }

    public function test_dos_disparos_simultaneos_solo_ejecutan_uno(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);

        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    private function contarBloqueadas(): int
    {
        return TiendanubeRestOperacionLog::where('operacion', 'sincronizar_precio')->where('resultado', 'bloqueada')->count();
    }

    public function test_bloqueada_por_funcion_desactivada_deja_un_unico_registro(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $antes = $this->contarBloqueadas();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, $this->contarBloqueadas() - $antes);
    }

    public function test_bloqueada_por_modo_solo_lectura_deja_un_unico_registro(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $antes = $this->contarBloqueadas();
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, $this->contarBloqueadas() - $antes);
    }

    public function test_bloqueada_por_conexion_caida_deja_un_unico_registro(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $this->crearVinculoPendienteDePrecio($lista->id);
        $antes = $this->contarBloqueadas();
        TiendanubeConexionRest::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, $this->contarBloqueadas() - $antes);
    }

    /** US8 (spec 018 ampliación, FR-031, SC-012): el rechazo de un vínculo no interrumpe los demás envíos. */
    public function test_rechazo_de_un_vinculo_no_interrumpe_otros_envios_de_precio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $lista->id]);
        $vinculoOk = $this->crearVinculoPendienteDePrecio($lista->id, 100);
        $vinculoError = $this->crearVinculoPendienteDePrecio($lista->id, 200);

        Http::fake([
            "api.tiendanube.com/v1/*/products/*/variants/{$vinculoError->variant_id}" => Http::response(['message' => 'Variante despublicada'], 404),
            'api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200),
        ]);

        $respuesta = $this->postJson(route('productos.sincronizarPreciosTn'));

        $respuesta->assertOk()->assertJson(['actualizados' => 1, 'con_error' => 1]);
        $this->assertFalse($vinculoOk->fresh()->precio_pendiente);
        $this->assertTrue($vinculoError->fresh()->precio_pendiente);
    }
}
