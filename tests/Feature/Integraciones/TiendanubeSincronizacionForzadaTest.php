<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Stock\StockService;
use App\Services\Tiendanube\SincronizadorStock;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 035, US1/US2/US3: "Sincronización forzada" recorre TODOS los vínculos
 * (no sólo pendientes), actualiza stock y precio, respeta los mismos cortes
 * (FR-007) y el mismo candado (FR-008) que las sincronizaciones existentes.
 * Equivalente Tiendanube de MercadoLibreSincronizacionForzadaTest.
 */
class TiendanubeSincronizacionForzadaTest extends TestCase
{
    use RefreshDatabase;

    protected ListaPrecio $lista;

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

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $this->lista->id]);

        // PrecioProductoObserver sincroniza de inmediato al setear un precio en la
        // lista configurada — cada test tiene que activar su fake (fakearOk() u
        // otro) ANTES de crear cualquier vínculo con precio, si no sale un
        // request real y la conexión queda "Caida". Http::fake() es acumulativo
        // entre llamadas (no reemplaza) — el primer patrón registrado que
        // matchea gana, así que un fake acá en setUp le ganaría siempre a
        // cualquier override más específico agregado después en el test.
    }

    private function fakearOk(): void
    {
        Http::fake([
            'api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200),
            'api.tiendanube.com/v1/*/products/*' => Http::response(['id' => 1], 200),
        ]);
    }

    private function crearVinculoSinPendiente(float $precio = 100, ?string $tnProductId = null): TiendanubeVarianteProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = TiendanubeVarianteProducto::create([
            'variant_id' => $producto->id * 10,
            'tn_product_id' => $tnProductId ?? (string) $producto->id,
            'producto_id' => $producto->id,
        ]);

        app(StockService::class)->ajustar($producto, null, Deposito::first(), 5, 'carga inicial');
        $producto->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => $precio]);

        return $vinculo;
    }

    public function test_recorre_todos_los_vinculos_sin_pendientes_actualiza_stock_y_precio(): void
    {
        $this->fakearOk();
        $vinculoA = $this->crearVinculoSinPendiente(100);
        $vinculoB = $this->crearVinculoSinPendiente(200);

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(2, $respuesta->json('stock.actualizados'));
        $this->assertSame(2, $respuesta->json('precio.actualizados'));

        $this->assertFalse($vinculoA->fresh()->stock_pendiente);
        $this->assertNotNull($vinculoA->fresh()->stock_sincronizado_en);
        $this->assertFalse($vinculoB->fresh()->stock_pendiente);
    }

    public function test_bloqueada_por_modo_solo_lectura_no_dispara_ningun_request(): void
    {
        $this->fakearOk();
        $this->crearVinculoSinPendiente();
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(
            1,
            TiendanubeRestOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count()
        );
    }

    public function test_bloqueada_por_funcion_desactivada_no_dispara_ningun_request(): void
    {
        $this->fakearOk();
        $this->crearVinculoSinPendiente();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_rechazo_de_un_vinculo_no_interrumpe_el_resto(): void
    {
        // Closure en vez de array de patrones: el variant_id del vínculo que
        // debe fallar recién se conoce después de crearlo, y un array de
        // patrones es acumulativo con cualquier fake anterior — con una
        // closure referenciada por variable evitamos ese problema de orden.
        $variantIdConError = null;
        Http::fake(function ($request) use (&$variantIdConError) {
            if ($variantIdConError && str_contains($request->url(), "/variants/{$variantIdConError}")) {
                return Http::response(['message' => 'Producto no encontrado'], 404);
            }

            return Http::response(['id' => 1], 200);
        });

        $vinculoOk = $this->crearVinculoSinPendiente();
        $vinculoError = $this->crearVinculoSinPendiente();
        $variantIdConError = $vinculoError->variant_id;

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
        $this->assertSame(1, $respuesta->json('stock.con_error'));

        $this->assertFalse($vinculoOk->fresh()->stock_pendiente);
        $this->assertNotNull($vinculoError->fresh()->stock_error);
    }

    public function test_vinculo_incompleto_se_saltea_sin_request(): void
    {
        $this->fakearOk();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculoIncompleto = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => null, 'producto_id' => $producto->id]);
        app(StockService::class)->ajustar($producto, null, Deposito::first(), 5, 'carga inicial');

        $vinculoOk = $this->crearVinculoSinPendiente();

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
        $this->assertSame(1, $respuesta->json('stock.con_error'));
        $this->assertStringContainsString('Vínculo incompleto', $vinculoIncompleto->fresh()->stock_error);
        $this->assertFalse($vinculoOk->fresh()->stock_pendiente);
    }

    public function test_candado_tomado_devuelve_ya_hay_una_sincronizacion_en_curso(): void
    {
        $this->fakearOk();
        $this->crearVinculoSinPendiente();

        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    /** US3 (spec 035): vínculos ya sincronizados (sin ningún pendiente) igual se reenvían. */
    public function test_vinculos_ya_sincronizados_previamente_se_reenvian_igual(): void
    {
        $this->fakearOk();
        $vinculo = $this->crearVinculoSinPendiente();

        $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));
        $this->assertFalse($vinculo->fresh()->stock_pendiente);

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk();
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
    }

    public function test_sin_lista_de_precios_configurada_sincroniza_solo_stock(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => null]);
        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);
        $this->crearVinculoSinPendiente();

        $respuesta = $this->postJson(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
        $this->assertNull($respuesta->json('precio'));
    }
}
