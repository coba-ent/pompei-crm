<?php

namespace Tests\Feature\Informes\Contador;

use App\Models\EnvioContador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * T022/T024 (spec 087) — endpoints HTTP del modal: adjuntos previstos (T023), envío que valida y
 * encola (T024), protección de doble clic del lado del servidor (T021/FR-023). El job se testea
 * con `Bus::fake()`, **nunca contra un servidor de correo real** (memoria del proyecto).
 */
class EnvioContadorEndpointTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_adjuntos_previstos_sin_periodo_devuelve_vacio(): void
    {
        $response = $this->postJson('/informes/contador/adjuntos-previstos', []);

        $response->assertOk();
        $response->assertJson(['archivos' => []]);
    }

    public function test_adjuntos_previstos_con_anio_y_mes(): void
    {
        $response = $this->postJson('/informes/contador/adjuntos-previstos', ['anio' => 2026, 'mes' => 3]);

        $response->assertOk();
        $response->assertJson(['archivos' => [
            'IVA Ventas Marzo - 2026.xlsx', 'IVA Compras Marzo - 2026.xlsx', 'IVA Digital Marzo - 2026.zip',
        ]]);
    }

    public function test_adjuntos_previstos_rechaza_ambas_casillas_destildadas(): void
    {
        $response = $this->postJson('/informes/contador/adjuntos-previstos', [
            'anio' => 2026, 'mes' => 3, 'incluye_electronicas' => false, 'incluye_manuales' => false,
        ]);

        $response->assertOk();
        $response->assertJson(['archivos' => []]);
    }

    public function test_enviar_encola_el_job_y_registra_el_envio_como_pendiente(): void
    {
        Bus::fake();

        $response = $this->postJson('/informes/contador/enviar', [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'contador@estudio.com',
            'incluye_electronicas' => true, 'incluye_manuales' => false, 'incluye_pdfs' => false,
            'asunto' => 'Información de Test', 'cuerpo' => 'Hola, te enviamos la información.',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        Bus::assertDispatched(\App\Jobs\EnviarInformacionContador::class);
        $this->assertDatabaseHas('envios_contador', ['estado' => 'pendiente', 'anio' => 2026, 'mes' => 3]);
    }

    public function test_enviar_rechaza_destinatario_invalido_sin_encolar(): void
    {
        Bus::fake();

        $response = $this->postJson('/informes/contador/enviar', [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'no-es-un-mail',
            'incluye_electronicas' => true,
            'asunto' => 'x', 'cuerpo' => 'x',
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(\App\Jobs\EnviarInformacionContador::class);
        $this->assertDatabaseCount('envios_contador', 0);
    }

    public function test_enviar_rechaza_ambas_casillas_destildadas_sin_encolar(): void
    {
        Bus::fake();

        $response = $this->postJson('/informes/contador/enviar', [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'contador@estudio.com',
            'incluye_electronicas' => false, 'incluye_manuales' => false,
            'asunto' => 'x', 'cuerpo' => 'x',
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(\App\Jobs\EnviarInformacionContador::class);
    }

    /** FR-023: doble clic no manda el correo dos veces. */
    public function test_doble_clic_es_bloqueado_del_lado_del_servidor(): void
    {
        Bus::fake();

        $payload = [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'contador@estudio.com',
            'incluye_electronicas' => true, 'incluye_manuales' => false, 'incluye_pdfs' => false,
            'asunto' => 'x', 'cuerpo' => 'x',
        ];

        $this->postJson('/informes/contador/enviar', $payload)->assertOk();
        $response = $this->postJson('/informes/contador/enviar', $payload);

        $response->assertStatus(409);
        Bus::assertDispatchedTimes(\App\Jobs\EnviarInformacionContador::class, 1);
        $this->assertDatabaseCount('envios_contador', 1);
    }

    public function test_sin_permiso_de_informes_devuelve_403(): void
    {
        $usuarioSinPermiso = \App\Models\User::factory()->create();
        $this->actingAs($usuarioSinPermiso);

        $response = $this->postJson('/informes/contador/adjuntos-previstos', ['anio' => 2026]);

        $response->assertForbidden();
    }
}
