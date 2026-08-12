<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStock;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 063 — US4: los errores de sincronización de stock son visibles y no se
 * reintentan para siempre. FR-014 a FR-018.
 */
class ErroresSincronizacionStockTest extends TestCase
{
    use RefreshDatabase;

    protected Deposito $deposito;

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

        $this->deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function crearVinculoConStock(string $mlItemId = 'MLA1'): array
    {
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => $mlItemId, 'producto_id' => $producto->id, 'stock_pendiente' => true]);
        app(StockService::class)->ajustar($producto, null, $this->deposito, 5, 'carga inicial');

        return [$vinculo, $producto];
    }

    /** FR-015: a los 5 intentos consecutivos con el mismo error, deja de reintentarse y queda "requiere intervención". */
    public function test_a_los_5_intentos_consecutivos_con_el_mismo_error_se_corta(): void
    {
        [$vinculo] = $this->crearVinculoConStock();

        Http::fake(['api.mercadolibre.com/*' => Http::response(['message' => 'item blocked by seller reputation'], 403)]);

        for ($i = 1; $i <= 4; $i++) {
            $vinculo->update(['stock_pendiente' => true]);
            app(SincronizadorStock::class)->ejecutar();
            $vinculo->refresh();
            $this->assertSame($i, $vinculo->stock_intentos_fallidos, "intento {$i}");
            $this->assertFalse($vinculo->stock_requiere_intervencion, "todavía no debe cortar en el intento {$i}");
        }

        $vinculo->update(['stock_pendiente' => true]);
        app(SincronizadorStock::class)->ejecutar();

        $vinculo->refresh();
        $this->assertSame(5, $vinculo->stock_intentos_fallidos);
        $this->assertTrue($vinculo->stock_requiere_intervencion);
        $this->assertNotNull($vinculo->stock_error_desde);
    }

    /** FR-016/SC-004: una publicación bloqueada se excluye de la selección de pendientes — no se vuelve a llamar a la API por ella. */
    public function test_publicacion_bloqueada_se_excluye_de_pendientes_y_no_consume_llamadas(): void
    {
        [$vinculoBloqueado] = $this->crearVinculoConStock('MLA-BLOQ');
        $vinculoBloqueado->update([
            'stock_requiere_intervencion' => true,
            'stock_intentos_fallidos' => 5,
            'stock_error_desde' => now()->subHour(),
            'stock_error' => 'item blocked by seller reputation',
            'stock_pendiente' => true,
        ]);

        [$vinculoOk] = $this->crearVinculoConStock('MLA-OK');

        $llamadas = 0;
        Http::fake(function () use (&$llamadas) {
            $llamadas++;

            return Http::response(['id' => 'MLA-OK'], 200);
        });

        $resultado = app(SincronizadorStock::class)->ejecutar();

        $this->assertSame(1, $llamadas, 'La bloqueada no debe generar ninguna llamada a la API.');
        $this->assertSame(1, $resultado['actualizados']);
        $this->assertTrue($vinculoBloqueado->fresh()->stock_requiere_intervencion);
    }

    /**
     * FR-014: un error distinto al anterior reinicia el contador en 1, no lo acumula.
     *
     * `Http::fake()` apila los callbacks (el primero que responde algo distinto de null gana
     * para siempre) — así que para simular una segunda tanda de respuestas dentro del mismo test
     * hay que mutar un estado compartido, no volver a llamar a `Http::fake()`.
     */
    public function test_un_error_distinto_reinicia_el_contador(): void
    {
        [$vinculo] = $this->crearVinculoConStock();

        // 400 (no 500/429) para no disparar el reintento transitorio de ClienteMercadoLibre y
        // no hacer lenta la corrida de tests con sus `sleep()` de backoff.
        $codigoActual = 400;
        Http::fake(function () use (&$codigoActual) {
            return Http::response(['message' => 'x'], $codigoActual);
        });

        $vinculo->update(['stock_pendiente' => true]);
        app(SincronizadorStock::class)->ejecutar();
        $vinculo->update(['stock_pendiente' => true]);
        app(SincronizadorStock::class)->ejecutar();
        $this->assertSame(2, $vinculo->fresh()->stock_intentos_fallidos);

        $codigoActual = 403;
        $vinculo->update(['stock_pendiente' => true]);
        app(SincronizadorStock::class)->ejecutar();

        $vinculo->refresh();
        $this->assertSame(1, $vinculo->stock_intentos_fallidos, 'Error distinto: la racha se reinicia en 1, no se acumula.');
    }

    /** Ciclo de vida: sincronizar con éxito limpia contador, fecha y marca. */
    public function test_sincronizar_con_exito_limpia_contador_fecha_y_marca(): void
    {
        [$vinculo] = $this->crearVinculoConStock();
        $vinculo->update([
            'stock_intentos_fallidos' => 3,
            'stock_error_desde' => now()->subMinutes(20),
            'stock_error' => 'timeout de red',
        ]);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);
        app(SincronizadorStock::class)->ejecutar();

        $vinculo->refresh();
        $this->assertSame(0, $vinculo->stock_intentos_fallidos);
        $this->assertNull($vinculo->stock_error_desde);
        $this->assertNull($vinculo->stock_error);
        $this->assertFalse($vinculo->stock_requiere_intervencion);
        $this->assertSame(5, $vinculo->ultimo_stock_publicado);
    }

    /** FR-017: reactivar manualmente vuelve al ciclo normal, sin reenviar el stock viejo (edge case: envía el vigente al reactivar). */
    public function test_reactivar_vuelve_al_ciclo_normal_y_envia_el_stock_vigente(): void
    {
        [$vinculo, $producto] = $this->crearVinculoConStock();
        $vinculo->update([
            'stock_requiere_intervencion' => true,
            'stock_intentos_fallidos' => 5,
            'stock_error_desde' => now()->subHour(),
            'stock_error' => 'item blocked by seller reputation',
            'stock_pendiente' => false,
        ]);

        // El stock cambió mientras estaba bloqueada.
        app(StockService::class)->ajustar($producto, null, $this->deposito, 20, 'ajuste posterior al bloqueo');

        $response = $this->postJson(route('ingresos.mercadolibre.vinculaciones.reactivar', $vinculo));
        $response->assertOk();

        $vinculo->refresh();
        $this->assertFalse($vinculo->stock_requiere_intervencion);
        $this->assertSame(0, $vinculo->stock_intentos_fallidos);
        $this->assertNull($vinculo->stock_error_desde);
        $this->assertTrue($vinculo->stock_pendiente);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);
        app(SincronizadorStock::class)->ejecutar();

        // `ajustar()` suma sobre el stock inicial de `crearVinculoConStock()` (5): 5 + 20 = 25.
        Http::assertSent(fn ($request) => $request->data()['available_quantity'] === 25);
    }
}
