<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 035, US1/US2/US3: "Sincronización forzada" recorre TODOS los vínculos
 * (no sólo pendientes), actualiza stock y precio, respeta los mismos cortes
 * (FR-007) y el mismo candado (FR-008) que las sincronizaciones existentes.
 */
class MercadoLibreSincronizacionForzadaTest extends TestCase
{
    use RefreshDatabase;

    protected Deposito $deposito;

    protected ListaPrecio $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente',
            'refresh_token' => 'rtk-vigente',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);

        $this->deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $this->lista->id]);
    }

    private function crearVinculoSinPendiente(float $precio = 100): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-'.$producto->id, 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, $this->deposito, 5, 'carga inicial');
        $producto->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => $precio]);

        return $vinculo;
    }

    public function test_recorre_todos_los_vinculos_sin_pendientes_actualiza_stock_y_precio(): void
    {
        $vinculoA = $this->crearVinculoSinPendiente(100);
        $vinculoB = $this->crearVinculoSinPendiente(200);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(2, $respuesta->json('stock.actualizados'));
        $this->assertSame(2, $respuesta->json('precio.actualizados'));

        $this->assertFalse($vinculoA->fresh()->stock_pendiente);
        $this->assertNotNull($vinculoA->fresh()->stock_sincronizado_en);
        $this->assertNotNull($vinculoA->fresh()->precio_sincronizado_en);
        $this->assertFalse($vinculoB->fresh()->stock_pendiente);
    }

    public function test_bloqueada_por_modo_solo_lectura_no_dispara_ningun_request(): void
    {
        $this->crearVinculoSinPendiente();
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(
            1,
            MercadoLibreOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count()
        );
    }

    public function test_bloqueada_por_funcion_desactivada_no_dispara_ningun_request(): void
    {
        $this->crearVinculoSinPendiente();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_rechazo_de_un_vinculo_no_interrumpe_el_resto(): void
    {
        $vinculoOk = $this->crearVinculoSinPendiente();
        $vinculoError = $this->crearVinculoSinPendiente();

        Http::fake([
            "api.mercadolibre.com/items/{$vinculoError->ml_item_id}" => Http::response(['message' => 'item_paused'], 400),
            'api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200),
        ]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
        $this->assertSame(1, $respuesta->json('stock.con_error'));

        $this->assertFalse($vinculoOk->fresh()->stock_pendiente);
        $this->assertNotNull($vinculoError->fresh()->stock_error);
    }

    /**
     * `producto_id` tiene `cascadeOnDelete()` (ml_publicacion_producto): borrar
     * el producto borra el vínculo con él, así que "vínculo con producto
     * eliminado" nunca puede pasar con datos reales — el guard en
     * `procesarVinculos()` es defensivo. Se confirma acá el efecto de la
     * cascada en sí, no el guard (que queda cubierto por FR-010 vía
     * unit/integration del propio guard si el modelo llegara a soft-deletar).
     */
    public function test_borrar_el_producto_borra_el_vinculo_por_cascada(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-HUERFANO', 'producto_id' => $producto->id]);

        $producto->delete();

        $this->assertDatabaseMissing('ml_publicacion_producto', ['id' => $vinculo->id]);
    }

    public function test_candado_tomado_devuelve_ya_hay_una_sincronizacion_en_curso(): void
    {
        $this->crearVinculoSinPendiente();

        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    /** US3 (spec 035): vínculos ya sincronizados (sin ningún pendiente) igual se reenvían. */
    public function test_vinculos_ya_sincronizados_previamente_se_reenvian_igual(): void
    {
        $vinculo = $this->crearVinculoSinPendiente();
        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        // Primera corrida: ya queda "sincronizado", sin nada pendiente.
        $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));
        $this->assertFalse($vinculo->fresh()->stock_pendiente);

        // Segunda corrida forzada: se reenvía igual, no se saltea por no estar pendiente.
        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk();
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
    }

    public function test_sin_lista_de_precios_configurada_sincroniza_solo_stock(): void
    {
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => null]);
        $this->crearVinculoSinPendiente();

        Http::fake(['api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200)]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada'));

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $respuesta->json('stock.actualizados'));
        $this->assertNull($respuesta->json('precio'));
    }
}
