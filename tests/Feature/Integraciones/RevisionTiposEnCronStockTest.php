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
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 116 (US3): la revisión de tipos corre dentro del comando de stock, frenada por la misma
 * `frecuencia_sync_minutos`, y no por su propio reloj de 24 hs.
 */
class RevisionTiposEnCronStockTest extends TestCase
{
    use RefreshDatabase;

    private bool $multigetFalla = false;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
            'frecuencia_sync_minutos' => 15,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => Producto::factory()->create()->id, 'listing_type_id' => 'gold_pro']);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/items') {
                return $this->multigetFalla
                    ? Http::response(['message' => 'boom'], 500)
                    : Http::response([['code' => 200, 'body' => ['id' => 'MLA1', 'listing_type_id' => 'gold_pro']]], 200);
            }

            return Http::response([], 200);
        });
    }

    private function multigets(): int
    {
        return Http::recorded(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/items')->count();
    }

    public function test_el_comando_de_stock_revisa_los_tipos_cuando_paso_la_frecuencia(): void
    {
        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()->subMinutes(16)]);

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame(1, $this->multigets());
        $this->assertTrue(MercadoLibreConfiguracion::actual()->tipo_publicacion_ultima_sync_en->gt(now()->subMinute()));
    }

    public function test_antes_de_la_frecuencia_no_revisa(): void
    {
        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()->subMinutes(5)]);

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame(0, $this->multigets());
    }

    public function test_con_el_stock_bloqueado_que_reintenta_cada_minuto_revisa_una_sola_vez(): void
    {
        // Sólo lectura: el stock no avanza `stock_ultima_sync_en` y el comando entra cada minuto.
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true, 'tipo_publicacion_ultima_sync_en' => null]);

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();
        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame(1, $this->multigets());
    }

    public function test_en_modo_solo_lectura_igual_lee_los_tipos(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame(1, $this->multigets());
    }

    /** Con la función desactivada el cliente bloquea también las lecturas (FR-005b de spec 012): ni se intenta. */
    public function test_con_la_funcion_desactivada_no_consulta(): void
    {
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame(0, $this->multigets());
    }

    public function test_si_el_stock_se_rompe_la_revision_corre_igual(): void
    {
        Deposito::query()->update(['activo' => false]);

        try {
            $this->artisan('mercadolibre:sincronizar-stock');
        } catch (\RuntimeException) {
            // El stock sin depósito explota como siempre; lo que importa es que los tipos se revisaron.
        }

        $this->assertSame(1, $this->multigets());
    }

    public function test_una_falla_de_la_revision_no_cambia_el_resultado_del_stock(): void
    {
        $this->multigetFalla = true;

        $this->artisan('mercadolibre:sincronizar-stock')->assertSuccessful();

        $this->assertSame('gold_pro', MercadoLibrePublicacionProducto::sole()->listing_type_id);
    }

    public function test_sin_cuenta_conectada_no_consulta(): void
    {
        MercadoLibreCuenta::query()->delete();

        $this->artisan('mercadolibre:sincronizar-stock');

        $this->assertSame(0, $this->multigets());
    }

    public function test_el_comando_manual_con_forzar_sigue_funcionando(): void
    {
        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $this->assertSame(1, $this->multigets());
    }

    public function test_la_revision_de_tipos_ya_no_tiene_schedule_propio(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('mercadolibre:sincronizar-stock')
            ->doesntExpectOutputToContain('mercadolibre:sincronizar-tipos-publicacion')
            ->assertSuccessful();
    }
}
