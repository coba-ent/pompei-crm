<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorPrecios;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 016): "Sincronizar precios ahora" — contracts §1. No concurrencia
 * (FR-015) y los cortes de FR-011/FR-012 con un único registro en el
 * historial, no uno por vínculo pendiente (research.md R5).
 */
class MercadoLibreSincronizarPreciosTest extends TestCase
{
    use RefreshDatabase;

    protected ListaPrecio $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $this->lista->id]);
    }

    private function crearVinculoPendiente(float $precio = 100): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();
        $producto->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => $precio]);

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA'.$producto->id, 'producto_id' => $producto->id, 'precio_pendiente' => true,
        ]);
    }

    public function test_devuelve_los_contadores_esperados(): void
    {
        $this->crearVinculoPendiente();
        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'actualizados' => 1, 'con_error' => 0]);
    }

    public function test_dos_disparos_simultaneos_solo_ejecutan_uno(): void
    {
        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
        } finally {
            $lock->release();
        }
    }

    public function test_sin_lista_de_precios_configurada_responde_bloqueada(): void
    {
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => null]);

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertStatus(409);
        $this->assertSame('No hay ninguna Lista de Precios configurada para Mercado Libre.', $respuesta->json('mensaje'));
    }

    public function test_bloqueada_por_funcion_desactivada_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_precio')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_modo_solo_lectura_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_precio')->where('resultado', 'bloqueada')->count());
    }

    public function test_bloqueada_por_conexion_caida_deja_un_unico_registro(): void
    {
        $this->crearVinculoPendiente();
        $this->crearVinculoPendiente();
        MercadoLibreCuenta::query()->delete();
        Http::fake();

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'sincronizar_precio')->where('resultado', 'bloqueada')->count());
    }

    /** US3 escenario 4: un producto vinculado después de un cambio de precio ya ocurrido se sincroniza igual. */
    public function test_vinculo_creado_despues_de_un_cambio_de_precio_se_sincroniza_al_presionar_el_boton(): void
    {
        $producto = Producto::factory()->create();
        $producto->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => 300]);

        // El vínculo se crea DESPUÉS del cambio de precio: nunca disparó el evento automático,
        // por eso arranca sin precio_pendiente marcado por el Observer.
        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA'.$producto->id, 'producto_id' => $producto->id, 'precio_pendiente' => true,
        ]);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);

        $respuesta = $this->postJson(route('productos.sincronizarPreciosMl'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'actualizados' => 1]);
        $this->assertFalse($vinculo->fresh()->precio_pendiente);
    }
}
