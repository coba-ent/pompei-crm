<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
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
    }

    public function test_guarda_la_configuracion_de_ventas(): void
    {
        $admin = \App\Models\Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

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
}
