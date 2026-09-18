<?php

namespace Tests\Feature\Auth;

use App\Models\User;
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
}
