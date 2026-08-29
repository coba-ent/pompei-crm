<?php

namespace Tests\Feature\Informes\Contador;

use App\Jobs\EnviarInformacionContador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * Regresión del incidente del 28/08/2026: el envío con un adjunto propio moría en el worker con
 * `Unable to open path ".../storage/app/adjuntos-contador-tmp/xxx.png"`. El archivo se subía bien,
 * pero la ruta se componía a mano como `storage_path('app/'.$ruta)`, y en Laravel 11+ el disco
 * `local` tiene su raíz en `storage/app/private` — así que apuntaba a un archivo inexistente.
 *
 * Lo que fija el contrato es que la ruta que se le pasa al job **exista en disco**: comparar contra
 * un prefijo esperado volvería a romperse (en silencio) si la raíz del disco cambia otra vez.
 */
class AdjuntoPropioRutaTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_la_ruta_del_adjunto_propio_existe_en_disco(): void
    {
        Bus::fake();

        $response = $this->post('/informes/contador/enviar', [
            'anio' => 2026, 'mes' => 3,
            'destinatarios' => 'contador@estudio.com',
            'incluye_electronicas' => true, 'incluye_manuales' => false, 'incluye_pdfs' => false,
            'asunto' => 'Con adjunto', 'cuerpo' => 'Va con un archivo propio.',
            'adjuntos_propios' => [UploadedFile::fake()->create('comprobante.png', 12)],
        ]);

        $response->assertOk();

        Bus::assertDispatched(EnviarInformacionContador::class, function ($job) {
            $adjuntos = (new \ReflectionProperty($job, 'adjuntosPropios'))->getValue($job);

            $this->assertArrayHasKey('comprobante.png', $adjuntos, 'El adjunto propio se pierde antes de llegar al job.');

            // El assert que importa: la ruta tiene que resolver a un archivo real, que es
            // justamente lo que fallaba en producción.
            $this->assertFileExists(
                $adjuntos['comprobante.png'],
                'La ruta del adjunto propio no existe en disco: el envío va a fallar en el worker.'
            );

            return true;
        });
    }
}
