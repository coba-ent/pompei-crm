<?php

namespace App\Jobs;

use App\Models\Integraciones\MercadoLibreBotConfiguracion;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreSugerencia;
use App\Services\MercadoLibre\Bot\GeneradorDeSugerencias;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Genera una sugerencia de respuesta con IA para un mensaje entrante (spec
 * 033, US2, FR-004/FR-005). Corre asíncrono (colas reales en producción,
 * síncrono en local — R7 de research.md) para no bloquear el webhook.
 *
 * Un mismo mensaje puede tener varias sugerencias a lo largo del tiempo (Edge
 * Case: pedido bajo demanda con una sugerencia previa vigente) — cada
 * ejecución crea una fila nueva en `generando`, nunca reutiliza una existente
 * (data-model.md § `ml_sugerencias`).
 */
class GenerarSugerenciaMercadoLibre implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout único sin reintentos automáticos (FR-011a, R8 de research.md). */
    public int $timeout = 30;

    public int $tries = 1;

    /** Límite real que aplica Mercado Libre a los mensajes del vendedor (R8 de research.md). */
    private const MAX_CARACTERES = 350;

    public function __construct(public readonly MercadoLibreMensaje $mensaje) {}

    public function handle(GeneradorDeSugerencias $generador): void
    {
        $sugerencia = MercadoLibreSugerencia::create([
            'ml_mensaje_id' => $this->mensaje->id,
            'estado' => 'generando',
        ]);

        try {
            $instrucciones = MercadoLibreBotConfiguracion::actual()->instrucciones_tono ?? '';
            $texto = $generador->generar($this->mensaje->conversacion, $this->mensaje, $instrucciones);

            if ($texto === '') {
                $sugerencia->update([
                    'estado' => 'error',
                    'error_mensaje' => 'El proveedor de IA devolvió una respuesta vacía.',
                ]);

                return;
            }

            if (mb_strlen($texto) > self::MAX_CARACTERES) {
                $sugerencia->update([
                    'estado' => 'error',
                    'error_mensaje' => 'La respuesta generada supera los '.self::MAX_CARACTERES.' caracteres permitidos por Mercado Libre.',
                ]);

                return;
            }

            $sugerencia->update([
                'texto_sugerido' => $texto,
                'estado' => 'lista',
                'generada_en' => now(),
            ]);
        } catch (Throwable $e) {
            $sugerencia->update([
                'estado' => 'error',
                'error_mensaje' => 'No se pudo generar la sugerencia: '.$e->getMessage(),
            ]);
        }
    }
}
