<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreSolicitudVinculacion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 011): flujo OAuth completo, con Http::fake() (el flujo real no
 * puede probarse en local — plan.md). FR-015..FR-022.
 */
class MercadoLibreOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ]);
    }

    private function emitirSolicitud(): MercadoLibreSolicitudVinculacion
    {
        return MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');
    }

    private function fakeCanjeYUsuario(array $overridesToken = [], array $overridesUsuario = []): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(array_merge([
                'access_token' => 'APP_USR-token-abc',
                'token_type' => 'bearer',
                'expires_in' => 10800,
                'scope' => 'offline_access read write',
                'user_id' => 555111,
                'refresh_token' => 'TG-refresh-abc',
            ], $overridesToken), 200),
            'api.mercadolibre.com/users/me' => Http::response(array_merge([
                'id' => 555111,
                'nickname' => 'TESTUSER555',
                'email' => 'test555@testuser.com',
                'site_id' => 'MLA',
                'user_type' => 'normal',
            ], $overridesUsuario), 200),
        ]);
    }

    public function test_canje_exitoso_persiste_tokens_cifrados_y_datos_de_cuenta(): void
    {
        $this->fakeCanjeYUsuario();
        $solicitud = $this->emitirSolicitud();

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => $solicitud->state]));

        $response->assertRedirect(route('configuracion.mercadolibre.index'));
        $response->assertSessionHas('ml_exito');

        $cuenta = MercadoLibreCuenta::where('ml_user_id', 555111)->firstOrFail();
        $this->assertSame(EstadoConexion::Conectada, $cuenta->estado);
        $this->assertSame('TESTUSER555', $cuenta->nickname);
        $this->assertSame('APP_USR-token-abc', $cuenta->access_token);

        $crudo = \DB::table('ml_cuentas')->where('id', $cuenta->id)->first();
        $this->assertStringNotContainsString('APP_USR-token-abc', $crudo->access_token);

        $this->assertSame('consumida', $solicitud->fresh()->estado);
    }

    public function test_state_inexistente_es_rechazado(): void
    {
        $this->fakeCanjeYUsuario();

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => 'state-que-no-existe']));

        $response->assertRedirect(route('configuracion.mercadolibre.index'));
        $response->assertSessionHas('ml_error');
        $this->assertDatabaseCount('ml_cuentas', 0);
    }

    public function test_state_vencido_es_rechazado(): void
    {
        $this->fakeCanjeYUsuario();
        $solicitud = $this->emitirSolicitud();
        $solicitud->update(['expira_en' => now()->subMinutes(1)]);

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => $solicitud->state]));

        $response->assertSessionHas('ml_error');
        $this->assertDatabaseCount('ml_cuentas', 0);
    }

    public function test_state_ya_consumido_no_dispara_un_segundo_canje_y_no_rompe_la_conexion(): void
    {
        $this->fakeCanjeYUsuario();
        $solicitud = $this->emitirSolicitud();

        $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => $solicitud->state]))
            ->assertSessionHas('ml_exito');

        Http::fake(); // cualquier segunda llamada a la API sería un bug.

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => $solicitud->state]));

        $response->assertSessionHas('ml_info');
        $this->assertDatabaseCount('ml_cuentas', 1);
        $this->assertSame(EstadoConexion::Conectada, MercadoLibreCuenta::first()->estado);
    }

    public function test_cuenta_de_sitio_distinto_es_rechazada_sin_persistir_nada(): void
    {
        $this->fakeCanjeYUsuario(overridesUsuario: ['site_id' => 'MLB']);
        $solicitud = $this->emitirSolicitud();

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-code', 'state' => $solicitud->state]));

        $response->assertSessionHas('ml_error');
        $this->assertDatabaseCount('ml_cuentas', 0);
    }

    public function test_error_access_denied_no_deja_datos_parciales(): void
    {
        $solicitud = $this->emitirSolicitud();

        $response = $this->get(route('configuracion.mercadolibre.callback', [
            'error' => 'access_denied',
            'error_description' => 'El usuario canceló la autorización',
            'state' => $solicitud->state,
        ]));

        $response->assertSessionHas('ml_info');
        $this->assertDatabaseCount('ml_cuentas', 0);
        // El state no se consume: no hubo canje.
        $this->assertSame('pendiente', $solicitud->fresh()->estado);
    }
}
