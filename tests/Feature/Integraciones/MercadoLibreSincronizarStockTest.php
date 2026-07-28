<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
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
 * US3 (spec 013): "Sincronizar stock ahora" — contracts §1. No concurrencia
 * (FR-008) y los tres cortes de FR-009/FR-010 con un único registro en el
 * historial (research.md R7), no uno por vínculo pendiente.
 */
class MercadoLibreSincronizarStockTest extends TestCase
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
            'access_token' => 'atk',
            'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function crearVinculoPendiente(int $stockInicial = 5): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA'.$producto->id,
            'producto_id' => $producto->id,
        ]);

        app(StockService::class)->ajustar($producto, null, Deposito::first(), $stockInicial, 'carga inicial');
        $vinculo->update(['stock_pendiente' => true]);

        return $vinculo;
    }

    public function test_devuelve_los_contadores_esperados(): void
    {
        $this->crearVinculoPendiente();
        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.sincronizarStock'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'actualizados' => 1, 'con_error' => 0]);
    }

    public function test_dos_disparos_simultaneos_solo_ejecutan_uno(): void
    {
        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('ingresos.mercadolibre.sincronizarStock'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    public function test_bloqueada_por_funcion_desactivada_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_modo_solo_lectura_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_conexion_caida_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        MercadoLibreCuenta::query()->delete();
        Http::fake();

        $respuesta = $this->postJson(route('ingresos.mercadolibre.sincronizarStock'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_stock')->where('resultado', 'bloqueada')->count());
    }
}
