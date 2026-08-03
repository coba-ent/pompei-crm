<?php

namespace App\Console\Commands;

use App\Models\CertificadoFiscal;
use Illuminate\Console\Command;

/** FR-016: avisa (log + salida de consola) cuando el certificado fiscal activo está vencido o próximo a vencer. */
class ArcaAvisarVencimientoCertificado extends Command
{
    protected $signature = 'arca:avisar-vencimiento-certificado';

    protected $description = 'Revisa el certificado fiscal activo y avisa si está vencido o próximo a vencer (FR-016)';

    public function handle(): int
    {
        $certificado = CertificadoFiscal::activo();

        if (! $certificado || ! $certificado->fecha_vencimiento) {
            $this->info('No hay certificado fiscal activo con fecha de vencimiento cargada.');

            return self::SUCCESS;
        }

        if ($certificado->vencido()) {
            $mensaje = "Certificado fiscal ARCA vencido desde {$certificado->fecha_vencimiento->format('d/m/Y')} — las emisiones usan el fallback local sin validez fiscal.";
            $this->error($mensaje);
            logger()->error($mensaje);

            return self::FAILURE;
        }

        $diasAviso = (int) config('arca.dias_aviso_vencimiento_certificado', 30);
        if ($certificado->proximoAVencer($diasAviso)) {
            $mensaje = "Certificado fiscal ARCA próximo a vencer: {$certificado->fecha_vencimiento->format('d/m/Y')}.";
            $this->warn($mensaje);
            logger()->warning($mensaje);

            return self::SUCCESS;
        }

        $this->info('Certificado fiscal ARCA vigente, sin aviso pendiente.');

        return self::SUCCESS;
    }
}
