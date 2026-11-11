<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\CredencialRecordada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard.index', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Regresión: `AplicarDuracionSesion` (middleware) corre ANTES que este controller en
     * el pipeline de `web`, así que en el request del login mismo leía la preferencia
     * VIEJA de "mantener sesión" (la sesión todavía no tenía la nueva) — la cookie de esa
     * respuesta salía siempre con la duración corta, aunque el checkbox estuviera
     * tildado. Recién se corregía en el segundo request. Ver
     * AplicarDuracionSesion::aplicar(), llamado también desde el controller.
     */
    public function test_mantener_sesion_iniciada_extiende_la_duracion_desde_el_login_mismo(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'mantener_sesion' => '1',
        ]);

        $this->assertAuthenticated();
        $this->assertFalse(config('session.expire_on_close'));
        $this->assertSame(60 * 24 * 30, config('session.lifetime'));
    }

    public function test_sin_mantener_sesion_iniciada_la_cookie_sigue_expirando_al_cerrar_el_navegador(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertTrue(config('session.expire_on_close'));
        $this->assertSame(120, config('session.lifetime'));
    }

    /**
     * Regresión: el handler de sesión se arma (en StartSession) con el `session.lifetime`
     * del archivo de config, antes que AplicarDuracionSesion. Valía 120, así que el server
     * daba por vencida la sesión a las 2 h aunque se hubiera tildado "mantener sesión".
     */
    public function test_el_handler_de_sesion_acepta_sesiones_de_30_dias(): void
    {
        $handler = $this->app['session']->driver()->getHandler();
        $minutos = (new \ReflectionProperty($handler, 'minutes'))->getValue($handler);

        $this->assertSame(60 * 24 * 30, $minutos);
    }

    public function test_sin_mantener_sesion_se_corta_a_las_2_horas_sin_actividad(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['mantener_sesion_activa' => false, 'ultima_actividad' => now()->subMinutes(121)->timestamp])
            ->get(route('dashboard.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_sin_mantener_sesion_sigue_activa_antes_de_las_2_horas(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['mantener_sesion_activa' => false, 'ultima_actividad' => now()->subMinutes(100)->timestamp])
            ->get(route('dashboard.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_con_mantener_sesion_no_se_corta_por_inactividad(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['mantener_sesion_activa' => true, 'ultima_actividad' => now()->subDays(5)->timestamp])
            ->get(route('dashboard.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_recordarme_deja_la_cuenta_para_guardar_en_el_navegador(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
            ->assertSessionHas('login_recordado.email', $user->email)
            ->assertSessionHas('login_recordado.credencial');

        // La contraseña no viaja al navegador, ni en claro.
        $this->assertStringNotContainsString('password', json_encode(session('login_recordado')));
    }

    /**
     * Regresión: con un flash, el redirect intermedio intended "/" → "/dashboard" (lo
     * normal después de cerrar sesión) lo consumía y el navegador nunca guardaba nada.
     */
    public function test_recordarme_sobrevive_a_un_redirect_intermedio_despues_del_login(): void
    {
        $user = User::factory()->create();

        $this->withSession(['url.intended' => url('/')])
            ->followingRedirects()
            ->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
            ->assertSee("localStorage.setItem('pompei.login_recordado'", false)
            ->assertSee($user->email, false);

        // Se consume en la primera página dibujada: no se vuelve a escribir en cada página.
        $this->assertFalse(session()->has('login_recordado'));
    }

    public function test_sin_recordarme_no_se_guarda_ni_se_borra_nada(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionMissing('login_recordado')
            ->assertSessionMissing('login_olvidar');
    }

    public function test_destildar_recordarme_sobre_la_cuenta_recordada_la_olvida(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'olvidar_recordado' => '1'])
            ->assertSessionHas('login_olvidar', true);
    }

    public function test_se_puede_entrar_con_la_credencial_recordada_sin_escribir_la_contrasena(): void
    {
        $user = User::factory()->create();
        $credencial = app(CredencialRecordada::class)->emitir($user);

        $this->post('/login', ['email' => $user->email, 'credencial_recordada' => $credencial])
            ->assertRedirect(route('dashboard.index', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_la_credencial_recordada_deja_de_servir_si_cambia_la_contrasena(): void
    {
        $user = User::factory()->create();
        $credencial = app(CredencialRecordada::class)->emitir($user);

        $user->update(['password' => 'otra-clave-nueva']);

        $this->post('/login', ['email' => $user->email, 'credencial_recordada' => $credencial])
            ->assertSessionHasErrors('credencial_recordada');

        $this->assertGuest();
    }

    public function test_la_credencial_recordada_no_sirve_para_otro_email(): void
    {
        $user = User::factory()->create();
        $otro = User::factory()->create();
        $credencial = app(CredencialRecordada::class)->emitir($user);

        $this->post('/login', ['email' => $otro->email, 'credencial_recordada' => $credencial])
            ->assertSessionHasErrors('credencial_recordada');

        $this->assertGuest();
    }

    public function test_credencial_recordada_trucha_no_entra(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'credencial_recordada' => 'cualquier-cosa'])
            ->assertSessionHasErrors('credencial_recordada');

        $this->assertGuest();
    }

    public function test_sin_contrasena_ni_credencial_pide_la_contrasena(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }
}
