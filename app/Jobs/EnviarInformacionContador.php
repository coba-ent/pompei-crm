<?php

namespace App\Jobs;

use App\Mail\CorreoContador;
use App\Models\EnvioContador;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use App\Services\Informes\Contador\VerificadorTamanoAdjuntos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Genera los archivos, envía el correo y registra el resultado (spec 087, FR-021/FR-024).
 * Encolado (`ShouldQueue`) para no bloquear la request — ver research Decisión 2 sobre la
 * dependencia operativa del worker: con `QUEUE_CONNECTION=sync` este job corre igual, en el acto,
 * dentro de la misma request que lo despachó, y FR-021 no se cumple hasta que haya un worker real
 * corriendo en el VPS.
 */
class EnviarInformacionContador implements ShouldQueue
{
    use Queueable;

    /**
     * Generar el ZIP de PDFs de facturas (varias páginas con QR de ARCA cada una) puede tardar bastante
     * más que el default de Laravel (60s) — un timeout corto mataba el job a mitad de camino sin que el
     * `catch` de `handle()` llegara a correr (el kill es una señal externa del worker, no una excepción
     * normal). Incidente real: envío del 28/08/2026 quedó en `pendiente` para siempre por esto.
     */
    public int $timeout = 300;

    /** Sin reintentos automáticos: un envío de correo reintentado sin que el usuario lo sepa puede
     * duplicar el mail al contador. Si falla, el registro queda `fallido` y el usuario reintenta a mano. */
    public int $tries = 1;

    /**
     * @param  array<int, string>  $destinatarios
     * @param  array<string, string>  $adjuntosPropios  [nombre => ruta local temporal, ya subidos por el usuario]
     */
    public function __construct(
        private int $envioContadorId,
        private Periodo $periodo,
        private OpcionesEnvio $opciones,
        private array $destinatarios,
        private bool $copiaRemitente,
        private ?string $mailRemitente,
        private string $asunto,
        private string $cuerpo,
        private array $adjuntosPropios = [],
    ) {}

    public function handle(PaqueteContador $paquete): void
    {
        $envio = EnvioContador::find($this->envioContadorId);

        if ($envio === null) {
            return;
        }

        $generados = [];

        try {
            $generados = $paquete->generar(
                $this->periodo,
                $this->opciones,
                fn (string $etapa) => $envio->update(['etapa' => $etapa]),
            );
            $adjuntos = array_merge($generados, $this->adjuntosPropios);

            // FR-022/SC-006: recién acá se conoce el tamaño real de los adjuntos generados.
            $envio->update(['etapa' => 'verificando']);
            (new VerificadorTamanoAdjuntos)->verificar($adjuntos);

            $destinatarios = $this->destinatarios;
            if ($this->copiaRemitente && $this->mailRemitente) {
                $destinatarios[] = $this->mailRemitente;
            }

            $envio->update(['etapa' => 'correo']);
            Mail::to($destinatarios)->send(new CorreoContador($this->asunto, $this->cuerpo, $adjuntos));

            $envio->update(['estado' => 'enviado', 'etapa' => null, 'enviado_en' => now()]);
        } catch (\Throwable $e) {
            $envio->update(['estado' => 'fallido', 'error' => $e->getMessage()]);

            throw $e;
        } finally {
            foreach ($generados as $ruta) {
                @unlink($ruta);
            }
        }
    }

    /**
     * Laravel llama esto cuando el job falla definitivamente por una vía que NO pasa por el `catch`
     * de `handle()` — el caso real que motivó este método: un `TimeoutExceededException` lo dispara el
     * propio Worker matando el proceso desde afuera, así que el `try/catch` de arriba nunca corre. Sin
     * esto, `envios_contador` quedaba en `pendiente` para siempre y el usuario no se enteraba de que
     * el envío nunca salió (FR-019/FR-024, spec 087).
     */
    public function failed(\Throwable $e): void
    {
        $envio = EnvioContador::find($this->envioContadorId);

        if ($envio !== null && $envio->estado === 'pendiente') {
            $envio->update(['estado' => 'fallido', 'error' => $e->getMessage()]);
        }
    }
}
