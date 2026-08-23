<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Rol;
use App\Services\Tiendanube\SincronizadorOrdenes;
use App\Services\Tiendanube\SincronizadorStock;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un bloqueo NO es un fallo del comando.
 *
 * Los comandos de sincronización devolvían un exit code distinto de cero cuando la corrida quedaba
 * bloqueada por configuración (modo sólo lectura, o la función desactivada en Funciones Avanzadas).
 * El scheduler de Laravel interpreta cualquier exit ≠ 0 como fallo y lo registra como
 * `production.ERROR`; como el cron corre por minuto, eso generó **127 MB de `laravel.log` en el VPS
 * (16.649 entradas en 20 días)** por una configuración que era deliberada y correcta. Peor todavía:
 * un error real quedaba enterrado entre miles de falsas alarmas.
 *
 * Estos tests fijan que un bloqueo termina en 0 **sin llamar a la API**, y que un fallo real sigue
 * devolviendo un exit distinto de cero.
 */
class SyncBloqueadaNoEsFalloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente-de-prueba',
            'store_id' => '999',
            'estado' => EstadoConexion::Conectada,
        ]);

        Http::fake();
    }

    /** El caso real del VPS: la conexión en modo sólo lectura. */
    public function test_el_modo_solo_lectura_no_reporta_fallo_en_la_sync_de_stock(): void
    {
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);

        $this->artisan('tiendanube:sincronizar-stock --forzar')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_el_modo_solo_lectura_no_reporta_fallo_en_la_sync_de_ordenes(): void
    {
        TiendanubeConexionRest::actual()->update(['modo_solo_lectura' => true]);

        $this->artisan('tiendanube:sincronizar-ordenes --forzar')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /** La función desactivada en Funciones Avanzadas es el otro camino de bloqueo. */
    public function test_la_funcion_desactivada_no_reporta_fallo(): void
    {
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => false]);

        $this->artisan('tiendanube:sincronizar-stock --forzar')->assertExitCode(0);
        $this->artisan('tiendanube:sincronizar-ordenes --forzar')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * La contracara, que es lo que hace útil al cambio: un fallo REAL sigue devolviendo un exit
     * distinto de cero, así que el scheduler lo sigue registrando y se puede ver en el log.
     *
     * Se mockea el sincronizador en vez de provocar un 500 de la API porque lo que hay que fijar
     * es exactamente la traducción `tipo` → exit code que hace el comando; el camino que convierte
     * un 500 en `tipo => 'error'` (reintentos y `SincronizacionFallidaException`) ya está cubierto
     * por los tests del propio sincronizador.
     */
    public function test_un_fallo_real_sigue_reportando_exit_distinto_de_cero(): void
    {
        $this->mock(SincronizadorOrdenes::class, function ($mock) {
            $mock->shouldReceive('ejecutar')->once()->andReturn([
                'ok' => false,
                'tipo' => 'error',
                'mensaje' => 'No se pudo sincronizar con Tiendanube.',
            ]);
        });

        $this->artisan('tiendanube:sincronizar-ordenes --forzar')->assertExitCode(2);
    }

    /** `salteada` (ya hay otra corrida en curso) tampoco es un fallo. */
    public function test_una_corrida_salteada_no_reporta_fallo(): void
    {
        $this->mock(SincronizadorStock::class, function ($mock) {
            $mock->shouldReceive('ejecutar')->once()->andReturn([
                'ok' => false,
                'tipo' => 'salteada',
                'mensaje' => 'Ya hay una sincronización de stock en curso.',
            ]);
        });

        $this->artisan('tiendanube:sincronizar-stock --forzar')->assertExitCode(0);
    }
}
