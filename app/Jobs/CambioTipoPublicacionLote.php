<?php

namespace App\Jobs;

use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion as Cambio;
use App\Models\Integraciones\MercadoLibrePublicacionProducto as Vinculo;
use App\Services\MercadoLibre\CambiadorTipoPublicacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cambio de tipo masivo en segundo plano (spec 115, US3, research R5). Mismo patrón que la
 * Sincronización forzada: estado en Cache que la pantalla consulta por polling.
 *
 * Cada publicación pasa por `CambiadorTipoPublicacion::ejecutar()` —las mismas reglas que el
 * cambio individual— y toma el candado de precios de a una, no por todo el lote, para no frenar
 * al observer de precios durante minutos. La falla de una no frena a las demás (FR-015).
 */
class CambioTipoPublicacionLote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESTADO_CACHE_KEY = 'ml:cambio_tipo_lote:estado';

    public const ESTADO_TTL_MINUTOS = 30;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly string $loteId,
        public readonly array $ids,
        public readonly string $destino,
        public readonly ?int $usuarioId,
    ) {}

    public function handle(CambiadorTipoPublicacion $cambiador): void
    {
        $resumen = array_fill_keys(Cambio::RESULTADOS, 0);
        $detalle = [];
        $procesadas = 0;

        try {
            foreach (Vinculo::whereIn('id', $this->ids)->get() as $vinculo) {
                try {
                    $resultado = $cambiador->ejecutar($vinculo, $this->destino, $this->usuarioId, $this->loteId);
                } catch (Throwable $e) {
                    report($e);
                    $resultado = ['resultado' => Cambio::RESULTADO_FALLIDO, 'mensaje' => 'Error inesperado: '.$e->getMessage()];
                }

                $resumen[$resultado['resultado']]++;

                if (! in_array($resultado['resultado'], [Cambio::RESULTADO_CAMBIADO, Cambio::RESULTADO_SIN_CAMBIOS], true)) {
                    $detalle[] = ['ml_item_id' => $vinculo->ml_item_id, 'resultado' => $resultado['resultado'], 'mensaje' => $resultado['mensaje']];
                }

                $procesadas++;
                $this->guardarEstado(['estado' => 'en_curso', 'procesadas' => $procesadas, 'resumen' => $resumen]);
            }

            $this->guardarEstado([
                'estado' => 'terminado',
                'procesadas' => $procesadas,
                'resumen' => $resumen,
                'detalle' => $detalle,
                'terminado_en' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            report($e);

            $this->guardarEstado([
                'estado' => 'error',
                'procesadas' => $procesadas,
                'resumen' => $resumen,
                'detalle' => $detalle,
                'mensaje' => 'El cambio de tipo masivo se interrumpió: '.$e->getMessage(),
                'terminado_en' => now()->toIso8601String(),
            ]);
        }
    }

    /** Si el job muere por timeout, que la pantalla no quede esperando un lote "en curso" para siempre. */
    public function failed(?Throwable $e): void
    {
        $this->guardarEstado([
            'estado' => 'error',
            'mensaje' => 'El cambio de tipo masivo no pudo terminar'.($e ? ': '.$e->getMessage() : '.'),
            'terminado_en' => now()->toIso8601String(),
        ]);
    }

    private function guardarEstado(array $estado): void
    {
        $actual = Cache::get(self::ESTADO_CACHE_KEY) ?? [];

        Cache::put(self::ESTADO_CACHE_KEY, [
            ...$actual,
            'lote_id' => $this->loteId,
            'destino' => $this->destino,
            'total' => count($this->ids),
            ...$estado,
        ], now()->addMinutes(self::ESTADO_TTL_MINUTOS));
    }
}
