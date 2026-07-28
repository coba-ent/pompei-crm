<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreSolicitudVinculacion;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FR-022: reemplazo de cuenta vinculada. Autorizar con una cuenta distinta de
 * la vigente NO la reemplaza directamente: queda pendiente_confirmacion hasta
 * que el usuario confirme, y la vigente sigue operando mientras tanto.
 */
class MercadoLibreReemplazoCuentaTest extends TestCase
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

    private function cuentaVigente(): MercadoLibreCuenta
    {
        return MercadoLibreCuenta::create([
            'ml_user_id' => 111,
            'nickname' => 'CUENTA_VIEJA',
            'email' => 'vieja@testuser.com',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vieja',
            'refresh_token' => 'rtk-vieja',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    private function fakeCanjeYUsuario(int $mlUserId, string $nickname): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'atk-nueva-'.$mlUserId,
                'token_type' => 'bearer',
                'expires_in' => 10800,
                'user_id' => $mlUserId,
                'refresh_token' => 'rtk-nueva-'.$mlUserId,
            ], 200),
            'api.mercadolibre.com/users/me' => Http::response([
                'id' => $mlUserId,
                'nickname' => $nickname,
                'email' => strtolower($nickname).'@testuser.com',
                'site_id' => 'MLA',
                'user_type' => 'normal',
            ], 200),
        ]);
    }

    public function test_autorizar_con_la_misma_cuenta_vigente_actualiza_tokens_sin_pedir_confirmacion(): void
    {
        $vigente = $this->cuentaVigente();
        $this->fakeCanjeYUsuario(111, 'CUENTA_VIEJA');
        $solicitud = MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-x', 'state' => $solicitud->state]));

        $response->assertSessionHas('ml_exito');
        $this->assertDatabaseCount('ml_cuentas', 1);
        $this->assertSame('atk-nueva-111', $vigente->fresh()->access_token);
        $this->assertSame(EstadoConexion::Conectada, $vigente->fresh()->estado);
    }

    public function test_autorizar_con_una_cuenta_distinta_crea_pendiente_sin_tocar_la_vigente(): void
    {
        $vigente = $this->cuentaVigente();
        $this->fakeCanjeYUsuario(222, 'CUENTA_NUEVA');
        $solicitud = MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');

        $response = $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-x', 'state' => $solicitud->state]));

        $response->assertSessionHas('ml_info');

        $this->assertSame(EstadoConexion::Conectada, $vigente->fresh()->estado);
        $this->assertSame('atk-vieja', $vigente->fresh()->access_token);

        $pendiente = MercadoLibreCuenta::pendienteConfirmacion()->firstOrFail();
        $this->assertSame(222, $pendiente->ml_user_id);
        $this->assertNotNull($pendiente->pendiente_expira_en);

        $this->assertDatabaseCount('ml_cuentas', 2);
    }

    public function test_confirmar_activa_la_nueva_y_desconecta_la_anterior_en_una_transaccion(): void
    {
        $vigente = $this->cuentaVigente();
        $this->fakeCanjeYUsuario(222, 'CUENTA_NUEVA');
        $solicitud = MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');
        $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-x', 'state' => $solicitud->state]));

        $response = $this->postJson(route('configuracion.mercadolibre.confirmarReemplazo'));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('estado', 'conectada');

        $this->assertSame(EstadoConexion::Desconectada, $vigente->fresh()->estado);
        $this->assertNull($vigente->fresh()->access_token);

        $nueva = MercadoLibreCuenta::where('ml_user_id', 222)->firstOrFail();
        $this->assertSame(EstadoConexion::Conectada, $nueva->estado);

        // Nunca dos conectada a la vez.
        $this->assertSame(1, MercadoLibreCuenta::conectada()->count());
    }

    public function test_descartar_elimina_la_pendiente_dejando_la_vigente_intacta(): void
    {
        $vigente = $this->cuentaVigente();
        $this->fakeCanjeYUsuario(222, 'CUENTA_NUEVA');
        $solicitud = MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');
        $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-x', 'state' => $solicitud->state]));

        $response = $this->deleteJson(route('configuracion.mercadolibre.descartarPendiente'));

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseCount('ml_cuentas', 1);
        $this->assertSame(EstadoConexion::Conectada, $vigente->fresh()->estado);
    }

    public function test_una_pendiente_vencida_se_descarta_y_confirmarla_devuelve_409(): void
    {
        $this->cuentaVigente();
        $this->fakeCanjeYUsuario(222, 'CUENTA_NUEVA');
        $solicitud = MercadoLibreSolicitudVinculacion::emitir(auth()->user(), '127.0.0.1');
        $this->get(route('configuracion.mercadolibre.callback', ['code' => 'TG-x', 'state' => $solicitud->state]));

        MercadoLibreCuenta::pendienteConfirmacion()->update(['pendiente_expira_en' => now()->subMinute()]);

        $response = $this->postJson(route('configuracion.mercadolibre.confirmarReemplazo'));

        $response->assertStatus(409)->assertJsonPath('ok', false);
    }
}
