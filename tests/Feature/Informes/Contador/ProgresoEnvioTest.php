<?php

namespace Tests\Feature\Informes\Contador;

use App\Jobs\EnviarInformacionContador;
use App\Models\EnvioContador;
use App\Models\User;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * Progreso en vivo del envío al contador.
 *
 * Motivación concreta: el envío del 28/08/2026 falló en el worker y el usuario no se enteró — el
 * dato del error estaba en la base, pero ninguna pantalla lo leía. Lo que fija este test es que el
 * resultado (sobre todo el fallo) llegue a quien hizo el envío.
 */
class ProgresoEnvioTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function envio(array $atributos = []): EnvioContador
    {
        return EnvioContador::create(array_merge([
            'user_id' => auth()->id(),
            'destinatarios' => 'contador@estudio.com',
            'anio' => 2026, 'mes' => 3,
            'archivos' => ['IVA Ventas Marzo - 2026.xlsx'],
            'asunto' => 'x', 'estado' => 'pendiente',
        ], $atributos));
    }

    public function test_el_envio_en_curso_informa_su_etapa_y_no_esta_finalizado(): void
    {
        $envio = $this->envio(['etapa' => 'pdfs']);

        $response = $this->getJson("/informes/contador/envios/{$envio->id}");

        $response->assertOk();
        $response->assertJsonPath('progreso.estado', 'pendiente');
        $response->assertJsonPath('progreso.finalizado', false);
        $response->assertJsonPath('progreso.rotulo', 'Generando PDFs de facturas');
        $this->assertLessThan(100, $response->json('progreso.porcentaje'));
    }

    /** El caso que motivó todo esto: el motivo del fallo tiene que ser legible desde la pantalla. */
    public function test_el_envio_fallido_expone_el_motivo_del_error(): void
    {
        $envio = $this->envio([
            'estado' => 'fallido',
            'etapa' => 'correo',
            'error' => 'Unable to open path "/var/www/.../adjunto.png".',
        ]);

        $response = $this->getJson("/informes/contador/envios/{$envio->id}");

        $response->assertOk();
        $response->assertJsonPath('progreso.finalizado', true);
        $response->assertJsonPath('progreso.rotulo', 'Falló el envío');
        $this->assertStringContainsString('Unable to open path', $response->json('progreso.error'));
    }

    public function test_el_envio_completado_llega_al_cien_por_ciento(): void
    {
        $envio = $this->envio(['estado' => 'enviado', 'etapa' => null, 'enviado_en' => now()]);

        $response = $this->getJson("/informes/contador/envios/{$envio->id}");

        $response->assertJsonPath('progreso.porcentaje', 100);
        $response->assertJsonPath('progreso.finalizado', true);
    }

    /** Mientras el correo sale la barra NO llega a 100: llegar y seguir esperando se lee como colgado. */
    public function test_la_barra_no_llega_a_cien_antes_de_terminar(): void
    {
        $envio = $this->envio(['etapa' => 'correo']);

        $porcentaje = $this->getJson("/informes/contador/envios/{$envio->id}")->json('progreso.porcentaje');

        $this->assertLessThan(100, $porcentaje);
    }

    public function test_no_se_puede_espiar_el_envio_de_otro_usuario(): void
    {
        $ajeno = EnvioContador::create([
            'user_id' => User::factory()->create()->id,
            'destinatarios' => 'privado@otro.com',
            'anio' => 2026, 'mes' => 3, 'archivos' => [],
            'asunto' => 'x', 'estado' => 'fallido', 'error' => 'secreto',
        ]);

        $this->getJson("/informes/contador/envios/{$ajeno->id}")->assertForbidden();
    }

    public function test_enviar_devuelve_el_id_para_poder_seguirlo(): void
    {
        Bus::fake();

        $response = $this->postJson('/informes/contador/enviar', [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'contador@estudio.com',
            'incluye_electronicas' => true, 'incluye_manuales' => false, 'incluye_pdfs' => false,
            'asunto' => 'x', 'cuerpo' => 'x',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('envio_id'), 'Sin el id, la pantalla no puede seguir el envío.');
        $response->assertJsonPath('progreso.finalizado', false);
    }

    public function test_el_historial_lista_los_envios_propios_y_no_los_ajenos(): void
    {
        $this->envio(['estado' => 'enviado']);
        EnvioContador::create([
            'user_id' => User::factory()->create()->id,
            'destinatarios' => 'privado@otro.com',
            'anio' => 2026, 'mes' => 3, 'archivos' => [], 'asunto' => 'x', 'estado' => 'enviado',
        ]);

        $response = $this->getJson('/informes/contador/envios');

        $response->assertOk();
        $this->assertCount(1, $response->json('envios'));
        $this->assertSame('contador@estudio.com', $response->json('envios.0.destinatarios'));
    }

    /**
     * El job tiene que ir dejando la etapa a medida que avanza — si no, la barra se queda en "En
     * cola" durante los minutos que tarda y no se distingue de un worker caído.
     */
    public function test_el_job_va_registrando_las_etapas_hasta_terminar(): void
    {
        Mail::fake();

        $envio = $this->envio();
        $etapasVistas = [];

        $paquete = \Mockery::mock(PaqueteContador::class);
        $paquete->shouldReceive('generar')->once()
            ->andReturnUsing(function ($periodo, $opciones, $alEmpezarEtapa = null) use (&$etapasVistas, $envio) {
                if ($alEmpezarEtapa) {
                    $alEmpezarEtapa('informes');
                    $etapasVistas[] = $envio->fresh()->etapa;
                }

                return [];
            });

        (new EnviarInformacionContador(
            $envio->id, new Periodo(2026, 3),
            new OpcionesEnvio(incluyeElectronicas: true, incluyeManuales: false, incluyePdfs: false),
            ['contador@estudio.com'], false, null, 'x', 'x',
        ))->handle($paquete);

        $this->assertSame(['informes'], $etapasVistas, 'La etapa no se persiste mientras el job corre.');
        $this->assertSame('enviado', $envio->fresh()->estado);
        $this->assertNull($envio->fresh()->etapa, 'Un envío terminado no debe quedar con una etapa a medias.');
    }
}
