<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US4 (spec 012): frecuencia configurada respetada, `--forzar` la ignora
 * pero no los bloqueos, y dos corridas simultáneas no se solapan (FR-010,
 * FR-014).
 */
class MercadoLibreProgramacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

        Http::fake(['api.mercadolibre.com/orders/search*' => Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200)]);

        $admin = \App\Models\Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_guarda_la_configuracion_de_ventas(): void
    {
        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => true,
            'frecuencia_sync_minutos' => 30,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 60,
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue(MercadoLibreConfiguracion::actual()->creacion_automatica);
        $this->assertSame(30, MercadoLibreConfiguracion::actual()->frecuencia_sync_minutos);
    }

    public function test_no_ejecuta_si_no_transcurrio_la_frecuencia(): void
    {
        MercadoLibreConfiguracion::actual()->update(['frecuencia_sync_minutos' => 15, 'ultima_sync_en' => now()->subMinutes(5)]);

        $this->artisan('mercadolibre:sincronizar-ordenes')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_ejecuta_si_transcurrio_la_frecuencia(): void
    {
        MercadoLibreConfiguracion::actual()->update(['frecuencia_sync_minutos' => 15, 'ultima_sync_en' => now()->subMinutes(20)]);

        $this->artisan('mercadolibre:sincronizar-ordenes')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/search'));
    }

    public function test_forzar_ignora_la_frecuencia_pero_no_los_bloqueos(): void
    {
        MercadoLibreConfiguracion::actual()->update(['frecuencia_sync_minutos' => 60, 'ultima_sync_en' => now()->subMinute()]);
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);

        $this->artisan('mercadolibre:sincronizar-ordenes --forzar')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_forzar_ejecuta_aunque_no_corresponda_por_frecuencia(): void
    {
        MercadoLibreConfiguracion::actual()->update(['frecuencia_sync_minutos' => 60, 'ultima_sync_en' => now()->subMinute()]);

        $this->artisan('mercadolibre:sincronizar-ordenes --forzar')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/search'));
    }

    public function test_dos_corridas_simultaneas_no_se_solapan(): void
    {
        $lock = Cache::lock(SincronizadorOrdenes::LOCK_KEY, 300);
        $lock->get();

        try {
            $resultado = app(SincronizadorOrdenes::class)->ejecutar();

            $this->assertFalse($resultado['ok']);
            $this->assertSame('salteada', $resultado['tipo']);
        } finally {
            $lock->release();
        }
    }

    // --- US1 (spec 016): configurar la Lista de Precios que gestiona Mercado Libre ---

    public function test_guardar_con_lista_de_precios_valida_persiste_el_valor(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista Mercado Libre', 'activo' => true]);

        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $lista->id,
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($lista->id, MercadoLibreConfiguracion::actual()->lista_precio_id);
    }

    public function test_guardar_sin_lista_de_precios_no_da_error(): void
    {
        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => null,
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertNull(MercadoLibreConfiguracion::actual()->lista_precio_id);
    }

    public function test_guardar_con_lista_de_precios_inexistente_rechaza_sin_tocar_el_resto(): void
    {
        MercadoLibreConfiguracion::actual()->update(['categoria_venta_id' => null]);

        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => 999999,
        ]);

        $respuesta->assertStatus(422);
        $this->assertNull(MercadoLibreConfiguracion::actual()->lista_precio_id);
    }

    // --- US5 (spec 016): cambiar la Lista de Precios configurada empuja de inmediato ---

    public function test_cambiar_lista_configurada_sincroniza_de_inmediato_los_vinculos(): void
    {
        Http::fake([
            'api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200),
        ]);

        $listaA = ListaPrecio::create(['nombre' => 'Lista A', 'activo' => true]);
        $listaB = ListaPrecio::create(['nombre' => 'Lista B', 'activo' => true]);

        $productoConPrecioB = Producto::factory()->create();
        $productoConPrecioB->precios()->create(['lista_precio_id' => $listaA->id, 'precio' => 100]);
        $productoConPrecioB->precios()->create(['lista_precio_id' => $listaB->id, 'precio' => 200]);
        $vinculoConPrecio = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $productoConPrecioB->id, 'vinculada_por' => auth()->id(),
        ]);

        $productoSinPrecioB = Producto::factory()->create();
        $productoSinPrecioB->precios()->create(['lista_precio_id' => $listaA->id, 'precio' => 50]);
        $vinculoSinPrecio = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA2', 'producto_id' => $productoSinPrecioB->id, 'vinculada_por' => auth()->id(),
        ]);

        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $listaA->id]);

        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $listaB->id,
        ]);

        $respuesta->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 200.0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/items/MLA2'));

        $this->assertTrue($vinculoConPrecio->fresh()->precio_sincronizado_en !== null);
        $this->assertFalse($vinculoSinPrecio->fresh()->precio_pendiente);
    }

    public function test_guardar_el_mismo_valor_de_lista_no_dispara_ningun_envio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista A', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        Http::fake();

        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $lista->id,
        ]);

        $respuesta->assertOk();
        Http::assertNothingSent();
    }

    public function test_cambiar_lista_con_modo_solo_lectura_guarda_igual_pero_no_empuja(): void
    {
        $listaA = ListaPrecio::create(['nombre' => 'Lista A', 'activo' => true]);
        $listaB = ListaPrecio::create(['nombre' => 'Lista B', 'activo' => true]);

        $producto = Producto::factory()->create();
        $producto->precios()->create(['lista_precio_id' => $listaB->id, 'precio' => 200]);
        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'vinculada_por' => auth()->id(),
        ]);

        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $listaA->id, 'modo_solo_lectura' => true]);

        Http::fake();

        $respuesta = $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'deposito_id' => null,
            'categoria_venta_id' => null,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $listaB->id,
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($listaB->id, MercadoLibreConfiguracion::actual()->lista_precio_id);
        Http::assertNothingSent();
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
    }
}
