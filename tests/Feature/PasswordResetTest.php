<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** US2 (spec 081) — definir nueva contraseña desde el link del email. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    public function test_token_valido_actualiza_la_contrasena_invalida_el_token_y_no_deja_sesion_iniciada(): void
    {
        $usuario = User::factory()->create();
        $token = Password::createToken($usuario);

        $response = $this->postJson(route('contrasena.actualizar'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Contraseña actualizada. Ya podés iniciar sesión.');
        $this->assertTrue(Hash::check('nuevaClave123', $usuario->fresh()->password));
        $this->assertGuest();

        // el mismo token ya no es reutilizable
        $response2 = $this->postJson(route('contrasena.actualizar'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'otraClave456',
            'password_confirmation' => 'otraClave456',
        ]);
        $response2->assertStatus(422);
    }

    public function test_password_y_confirmacion_no_coinciden_devuelve_422_sin_actualizar(): void
    {
        $usuario = User::factory()->create();
        $token = Password::createToken($usuario);
        $passwordOriginal = $usuario->password;

        $response = $this->postJson(route('contrasena.actualizar'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'nuevaClave123',
            'password_confirmation' => 'no-coincide',
        ]);

        $response->assertStatus(422);
        $this->assertSame($passwordOriginal, $usuario->fresh()->password);
    }

    public function test_token_invalido_es_rechazado_con_mensaje_claro(): void
    {
        $usuario = User::factory()->create();

        $response = $this->postJson(route('contrasena.actualizar'), [
            'token' => 'token-inexistente',
            'email' => $usuario->email,
            'password' => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Este link ya no es válido. Pedí uno nuevo desde el login.');
    }
}
