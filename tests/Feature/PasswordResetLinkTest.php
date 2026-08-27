<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** US1 (spec 081) — pedir link de recuperación desde el login, sin revelar si el email existe. */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    public function test_pedir_link_con_email_existente_devuelve_mensaje_generico_y_envia_notificacion(): void
    {
        Notification::fake();

        $usuario = User::factory()->create(['email' => 'existe@ejemplo.com']);

        $response = $this->postJson(route('contrasena.enviar-link'), ['email' => $usuario->email]);

        $response->assertOk()->assertJsonPath('message', 'Si el email existe, te enviamos un link para recuperar tu contraseña.');
        Notification::assertSentTo($usuario, ResetPasswordNotification::class);
    }

    public function test_pedir_link_con_email_inexistente_devuelve_el_mismo_mensaje_sin_enviar_notificacion(): void
    {
        Notification::fake();

        $response = $this->postJson(route('contrasena.enviar-link'), ['email' => 'no-existe@ejemplo.com']);

        $response->assertOk()->assertJsonPath('message', 'Si el email existe, te enviamos un link para recuperar tu contraseña.');
        Notification::assertNothingSent();
    }

    public function test_pedir_link_con_email_invalido_devuelve_422(): void
    {
        $response = $this->postJson(route('contrasena.enviar-link'), ['email' => 'no-es-un-email']);

        $response->assertStatus(422);
    }

    public function test_usuario_inactivo_no_recibe_notificacion_pero_respuesta_es_generica(): void
    {
        Notification::fake();

        $usuario = User::factory()->create(['email' => 'inactivo@ejemplo.com', 'activo' => false]);

        $response = $this->postJson(route('contrasena.enviar-link'), ['email' => $usuario->email]);

        $response->assertOk()->assertJsonPath('message', 'Si el email existe, te enviamos un link para recuperar tu contraseña.');
        Notification::assertNothingSent();
    }

    public function test_pedidos_repetidos_en_menos_de_60_segundos_no_generan_una_segunda_notificacion(): void
    {
        Notification::fake();

        $usuario = User::factory()->create(['email' => 'existe@ejemplo.com']);

        $this->postJson(route('contrasena.enviar-link'), ['email' => $usuario->email])->assertOk();
        $response = $this->postJson(route('contrasena.enviar-link'), ['email' => $usuario->email]);

        $response->assertOk()->assertJsonPath('message', 'Si el email existe, te enviamos un link para recuperar tu contraseña.');
        Notification::assertSentToTimes($usuario, ResetPasswordNotification::class, 1);
    }
}
