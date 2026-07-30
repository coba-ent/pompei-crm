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
use App\Services\MercadoLibre\SincronizadorPrecios;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US2 (spec 016): `enviarUno()` en éxito y en fallo (FR-008/FR-009/FR-010).
 * US4: el rechazo de un vínculo no interrumpe el resto de la corrida
 * (FR-010, SC-005) y el reintento ante 429/5xx lo cubre `ClienteMercadoLibre`
 * sin código propio (FR-009).
 */
class SincronizadorPreciosTest extends TestCase
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
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $this->lista->id]);
    }

    public function test_enviar_uno_con_exito_deja_el_vinculo_sincronizado(): void
    {
        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        Http::fake(['api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200)]);

        $resultado = app(SincronizadorPrecios::class)->enviarUno($vinculo, 1234.56);

        $this->assertTrue($resultado);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 1234.56);

        $vinculo->refresh();
        $this->assertFalse($vinculo->precio_pendiente);
        $this->assertNotNull($vinculo->precio_sincronizado_en);
        $this->assertNull($vinculo->precio_error);
    }

    public function test_bloqueado_por_modo_solo_lectura_no_envia_y_conserva_pendiente(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        Http::fake();

        $resultado = app(SincronizadorPrecios::class)->enviarUno($vinculo, 1234.56);

        $this->assertFalse($resultado);
        Http::assertNothingSent();
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
    }

    public function test_bloqueado_por_funcion_desactivada_no_envia_y_conserva_pendiente(): void
    {
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);

        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        Http::fake();

        $resultado = app(SincronizadorPrecios::class)->enviarUno($vinculo, 1234.56);

        $this->assertFalse($resultado);
        Http::assertNothingSent();
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
    }

    public function test_bloqueado_por_conexion_caida_no_envia_y_conserva_pendiente(): void
    {
        MercadoLibreCuenta::query()->delete();

        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        Http::fake();

        $resultado = app(SincronizadorPrecios::class)->enviarUno($vinculo, 1234.56);

        $this->assertFalse($resultado);
        Http::assertNothingSent();
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
    }

    /** US4 (FR-010, SC-005): el rechazo de un vínculo no interrumpe el resto de la corrida. */
    public function test_el_rechazo_de_un_vinculo_no_interrumpe_el_resto(): void
    {
        $productoOk = Producto::factory()->create();
        $productoOk->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => 100]);
        $vinculoOk = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-OK', 'producto_id' => $productoOk->id, 'precio_pendiente' => true,
        ]);

        $productoPausado = Producto::factory()->create();
        $productoPausado->precios()->create(['lista_precio_id' => $this->lista->id, 'precio' => 200]);
        $vinculoPausado = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-PAUSADO', 'producto_id' => $productoPausado->id, 'precio_pendiente' => true,
        ]);

        Http::fake([
            'api.mercadolibre.com/items/MLA-PAUSADO' => Http::response(['message' => 'item_paused'], 400),
            'api.mercadolibre.com/items/MLA-OK' => Http::response(['id' => 'MLA-OK'], 200),
        ]);

        $resultado = app(SincronizadorPrecios::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertSame(1, $resultado['con_error']);

        $vinculoOk->refresh();
        $this->assertFalse($vinculoOk->precio_pendiente);
        $this->assertNull($vinculoOk->precio_error);

        $vinculoPausado->refresh();
        $this->assertTrue($vinculoPausado->precio_pendiente, 'El vínculo con error debe seguir pendiente para reintentar.');
        $this->assertNotNull($vinculoPausado->precio_error);
        $this->assertNotNull($vinculoPausado->precio_error_en);
    }

    /** FR-009: el reintento ante 429/5xx lo cubre ClienteMercadoLibre, sin código propio en SincronizadorPrecios. */
    public function test_reintenta_ante_429_y_termina_sincronizado(): void
    {
        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $intentos = 0;
        Http::fake(function () use (&$intentos) {
            $intentos++;

            if ($intentos === 1) {
                return Http::response(['message' => 'too many requests'], 429);
            }

            return Http::response(['id' => 'MLA1'], 200);
        });

        $resultado = app(SincronizadorPrecios::class)->enviarUno($vinculo, 1234.56);

        $this->assertTrue($resultado);
        $this->assertSame(2, $intentos);

        $vinculo->refresh();
        $this->assertFalse($vinculo->precio_pendiente);
        $this->assertNull($vinculo->precio_error);
    }

    /** T031 (FR-013): ningún dato sensible llega al historial — reutiliza el saneado de ClienteMercadoLibre::registrarLog() (spec 011). */
    public function test_ningun_dato_sensible_llega_al_historial(): void
    {
        $producto = Producto::factory()->create();
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        Http::fake(['api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200)]);

        app(SincronizadorPrecios::class)->enviarUno($vinculo, 999.99);

        $log = \DB::table('ml_operaciones_log')->where('operacion', 'sincronizar_precio')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('atk-vigente', json_encode($log));
        $this->assertStringNotContainsString('access_token', json_encode($log));
    }
}
