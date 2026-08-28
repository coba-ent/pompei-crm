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
            $generados = $paquete->generar($this->periodo, $this->opciones);
            $adjuntos = array_merge($generados, $this->adjuntosPropios);

            // FR-022/SC-006: recién acá se conoce el tamaño real de los adjuntos generados.
            (new VerificadorTamanoAdjuntos)->verificar($adjuntos);

            $destinatarios = $this->destinatarios;
            if ($this->copiaRemitente && $this->mailRemitente) {
                $destinatarios[] = $this->mailRemitente;
            }

            Mail::to($destinatarios)->send(new CorreoContador($this->asunto, $this->cuerpo, $adjuntos));

            $envio->update(['estado' => 'enviado', 'enviado_en' => now()]);
        } catch (\Throwable $e) {
            $envio->update(['estado' => 'fallido', 'error' => $e->getMessage()]);

            throw $e;
        } finally {
            foreach ($generados as $ruta) {
                @unlink($ruta);
            }
        }
    }
}
