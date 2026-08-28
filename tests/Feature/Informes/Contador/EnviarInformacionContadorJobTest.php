<?php

namespace Tests\Feature\Informes\Contador;

use App\Jobs\EnviarInformacionContador;
use App\Mail\CorreoContador;
use App\Models\EnvioContador;
use App\Models\User;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * T022 (spec 087) — el job de envío: adjuntos correctos, múltiples destinatarios, copia al
 * remitente, y registro en estado `fallido` cuando falla. Siempre con `Mail::fake()` — **nunca**
 * contra un servidor de correo real (memoria del proyecto, plan §Riesgos).
 */
class EnviarInformacionContadorJobTest extends TestCase
{
    use RefreshDatabase;

    private function envioPendiente(array $overrides = []): EnvioContador
    {
        return EnvioContador::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'destinatarios' => 'contador@estudio.com',
            'copia_remitente' => false,
            'anio' => 2026,
            'mes' => null,
            'incluye_electronicas' => true,
            'incluye_manuales' => false,
            'incluye_pdfs' => false,
            'archivos' => ['IVA Ventas - 2026.xlsx', 'IVA Compras - 2026.xlsx'],
            'asunto' => 'Información de Test',
            'estado' => 'pendiente',
        ], $overrides));
    }

    public function test_envia_a_multiples_destinatarios_con_los_adjuntos_correctos(): void
    {
        Mail::fake();

        $envio = $this->envioPendiente();
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        (new EnviarInformacionContador(
            $envio->id, $periodo, $opciones,
            ['a@x.com', 'b@x.com'], false, null,
            'Información de Test', 'Cuerpo del correo',
        ))->handle(app(\App\Services\Informes\Contador\PaqueteContador::class));

        Mail::assertSent(CorreoContador::class, function (CorreoContador $mail) {
            return $mail->hasTo('a@x.com') && $mail->hasTo('b@x.com')
                && count($mail->adjuntos) === 2;
        });

        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertNotNull($envio->enviado_en);
    }

    public function test_copia_al_remitente_cuando_la_casilla_esta_tildada(): void
    {
        Mail::fake();

        $envio = $this->envioPendiente();
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        (new EnviarInformacionContador(
            $envio->id, $periodo, $opciones,
            ['contador@estudio.com'], true, 'usuario@negocio.com',
            'Información de Test', 'Cuerpo',
        ))->handle(app(\App\Services\Informes\Contador\PaqueteContador::class));

        Mail::assertSent(CorreoContador::class, fn (CorreoContador $mail) => $mail->hasTo('usuario@negocio.com'));
    }

    public function test_sin_copia_no_llega_al_remitente(): void
    {
        Mail::fake();

        $envio = $this->envioPendiente();
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        (new EnviarInformacionContador(
            $envio->id, $periodo, $opciones,
            ['contador@estudio.com'], false, 'usuario@negocio.com',
            'Información de Test', 'Cuerpo',
        ))->handle(app(\App\Services\Informes\Contador\PaqueteContador::class));

        Mail::assertSent(CorreoContador::class, fn (CorreoContador $mail) => ! $mail->hasTo('usuario@negocio.com'));
    }

    public function test_registra_fallido_cuando_el_envio_lanza(): void
    {
        // Mock directo del facade (sin Mail::fake(), que sólo intercepta y no permite simular una
        // excepción): fuerza la falla en el punto exacto de envío, nunca contra un servidor real
        // (memoria del proyecto).
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP caído'));

        $envio = $this->envioPendiente();
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        try {
            (new EnviarInformacionContador(
                $envio->id, $periodo, $opciones,
                ['contador@estudio.com'], false, null,
                'Información de Test', 'Cuerpo',
            ))->handle(app(\App\Services\Informes\Contador\PaqueteContador::class));
        } catch (\Throwable) {
            // el job relanza para que el sistema de colas lo trate como fallido — ya se registró abajo.
        }

        $envio->refresh();
        $this->assertSame('fallido', $envio->estado);
        $this->assertStringContainsString('SMTP caído', $envio->error);
    }

    /**
     * Incidente real (28/08/2026): un `TimeoutExceededException` lo dispara el propio Worker matando
     * el proceso desde afuera — nunca pasa por el `try/catch` de `handle()`. Laravel invoca `failed()`
     * en su lugar; sin este método, `envios_contador` quedaba en `pendiente` para siempre. No llama a
     * `handle()` a propósito: reproduce el caso exacto en el que `handle()` nunca llegó a correr.
     */
    public function test_failed_marca_el_envio_como_fallido_cuando_el_worker_mata_el_job_por_timeout(): void
    {
        $envio = $this->envioPendiente();
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        $job = new EnviarInformacionContador(
            $envio->id, $periodo, $opciones,
            ['contador@estudio.com'], false, null,
            'Información de Test', 'Cuerpo',
        );

        $job->failed(new \Illuminate\Queue\TimeoutExceededException(
            'App\\Jobs\\EnviarInformacionContador has timed out.'
        ));

        $envio->refresh();
        $this->assertSame('fallido', $envio->estado);
        $this->assertStringContainsString('timed out', $envio->error);
    }

    /** `failed()` no debe pisar un envío que ya se sabe que salió bien (orden de eventos improbable pero posible). */
    public function test_failed_no_pisa_un_envio_ya_marcado_como_enviado(): void
    {
        $envio = $this->envioPendiente(['estado' => 'enviado', 'enviado_en' => now()]);
        $periodo = new Periodo(2026);
        $opciones = new OpcionesEnvio(true, false, false);

        $job = new EnviarInformacionContador(
            $envio->id, $periodo, $opciones,
            ['contador@estudio.com'], false, null,
            'Información de Test', 'Cuerpo',
        );

        $job->failed(new \RuntimeException('llegó tarde'));

        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertNull($envio->error);
    }
}
