<?php

namespace Tests\Feature\Informes\Contador;

use App\Models\DatosEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * Botón "Guardar como predeterminado" del modal de envío al contador: fija `mail_contador`, el
 * mismo campo de Mi Perfil que precarga el modal en cada envío.
 */
class MailPredeterminadoContadorTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_guarda_el_mail_normalizado_sobre_la_fila_existente(): void
    {
        DatosEmpresa::create(['razon_social' => 'Pompei', 'mail_contador' => 'viejo@estudio.com']);

        $response = $this->postJson('/informes/contador/mail-predeterminado', [
            'destinatarios' => ' contador@estudio.com ,otro@estudio.com ',
        ]);

        $response->assertOk()->assertJson(['mail_contador' => 'contador@estudio.com, otro@estudio.com']);
        $this->assertSame(1, DatosEmpresa::count());
        $this->assertSame('contador@estudio.com, otro@estudio.com', DatosEmpresa::instancia()->mail_contador);
        $this->assertSame('Pompei', DatosEmpresa::instancia()->razon_social);
    }

    public function test_crea_la_fila_si_no_existe(): void
    {
        $this->postJson('/informes/contador/mail-predeterminado', ['destinatarios' => 'contador@estudio.com'])
            ->assertOk();

        $this->assertSame('contador@estudio.com', DatosEmpresa::instancia()->mail_contador);
    }

    public function test_rechaza_un_mail_invalido_sin_tocar_el_guardado(): void
    {
        DatosEmpresa::create(['mail_contador' => 'contador@estudio.com']);

        $this->postJson('/informes/contador/mail-predeterminado', ['destinatarios' => 'contador@estudio.com, nada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destinatarios.0', 'La dirección "nada" no es un mail válido.');

        $this->assertSame('contador@estudio.com', DatosEmpresa::instancia()->mail_contador);
    }
}
