<?php

namespace App\Services;

use App\Models\LogAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Punto único de escritura de logs_auditoria (spec 054) — usado por los Observers de cada entidad. */
class AuditoriaService
{
    /** Labels de "usuario" cuando el evento no tiene un usuario humano autenticado (research.md D2). */
    private const LABELS_ORIGEN = [
        'mercadolibre' => 'Ventas Online (Mercado Libre)',
        'tiendanube' => 'Ventas Online (Tiendanube)',
    ];

    /** Cada cuántos eventos buffereados se vacía solo, para acotar la ventana de pérdida (spec 074). */
    private const TAMANO_LOTE_BUFFER = 200;

    /** @var array<int, array<string, mixed>>|null Eventos acumulados; null = modo buffer apagado. */
    private ?array $buffer = null;

    /**
     * Enciende el modo buffer: los eventos se acumulan en memoria y se persisten con un INSERT
     * múltiple en vez de uno por evento. Sólo lo usa el importador, alrededor de cada tanda
     * (spec 074, SC-005) — el resto de la aplicación sigue escribiendo evento por evento.
     */
    public function iniciarBuffer(): void
    {
        $this->buffer = [];
    }

    /**
     * Persiste los eventos acumulados y apaga el modo buffer. Hereda el contrato de
     * `registrarEvento()`: nunca lanza — si el INSERT falla se loguea y se descarta el buffer.
     */
    public function vaciarBuffer(): void
    {
        $pendientes = $this->buffer ?? [];
        $this->buffer = null;

        $this->persistirLote($pendientes);
    }

    /**
     * Registra un evento de auditoría. Nunca lanza excepción: si la escritura falla, se loguea en
     * storage/logs y se continúa (plan.md Constraints — la Auditoría documenta, no gatea).
     */
    public function registrarEvento(
        string $tipoAccion,
        string $tipoOperacion,
        Model $entidad,
        string $detalle,
        ?float $total = null,
        ?string $origenSistema = null
    ): void {
        try {
            $usuario = Auth::user();

            $fila = [
                'usuario_id' => $usuario?->id,
                'usuario_nombre' => $usuario?->name ?? self::LABELS_ORIGEN[$origenSistema] ?? 'Sistema',
                'origen_sistema' => $usuario ? null : $origenSistema,
                'tipo_accion' => $tipoAccion,
                'tipo_operacion' => $tipoOperacion,
                'entidad_tipo' => $entidad::class,
                'entidad_id' => $entidad->getKey(),
                'detalle' => $detalle,
                'total' => $total,
            ];

            if ($this->buffer !== null) {
                $this->buffer[] = $fila + ['created_at' => now()];

                if (count($this->buffer) >= self::TAMANO_LOTE_BUFFER) {
                    $lote = $this->buffer;
                    $this->buffer = [];
                    $this->persistirLote($lote);
                }

                return;
            }

            LogAuditoria::create($fila);
        } catch (Throwable $e) {
            Log::error('AuditoriaService: fallo al registrar evento de auditoría', [
                'tipo_accion' => $tipoAccion,
                'tipo_operacion' => $tipoOperacion,
                'entidad_id' => $entidad->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * INSERT múltiple de un lote de eventos. Nunca lanza: un fallo se loguea y el lote se
     * descarta (mismo contrato que `registrarEvento()`).
     *
     * @param  array<int, array<string, mixed>>  $lote
     */
    private function persistirLote(array $lote): void
    {
        if ($lote === []) {
            return;
        }

        try {
            LogAuditoria::insert($lote);
        } catch (Throwable $e) {
            Log::error('AuditoriaService: fallo al vaciar el buffer de auditoría', [
                'eventos_descartados' => count($lote),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Query compartida entre el DataTable y la exportación (research.md Decisión 4). */
    public function queryFiltrado(array $filtros): Builder
    {
        $query = LogAuditoria::query()->orderByDesc('created_at');

        if (! empty($filtros['id'])) {
            $query->deId((int) $filtros['id']);
        }

        if (! empty($filtros['operacion'])) {
            $query->deOperacion((string) $filtros['operacion']);
        }

        if (! empty($filtros['usuario_id'])) {
            $query->deUsuario((int) $filtros['usuario_id']);
        } elseif (! empty($filtros['origen_sistema'])) {
            $query->where('origen_sistema', $filtros['origen_sistema']);
        }

        $desde = $filtros['fecha_desde'] ?? now()->toDateString();
        $hasta = $filtros['fecha_hasta'] ?? now()->toDateString();
        $query->entreFechas($desde, $hasta);

        return $query;
    }
}
