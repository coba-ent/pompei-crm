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

            LogAuditoria::create([
                'usuario_id' => $usuario?->id,
                'usuario_nombre' => $usuario?->name ?? self::LABELS_ORIGEN[$origenSistema] ?? 'Sistema',
                'origen_sistema' => $usuario ? null : $origenSistema,
                'tipo_accion' => $tipoAccion,
                'tipo_operacion' => $tipoOperacion,
                'entidad_tipo' => $entidad::class,
                'entidad_id' => $entidad->getKey(),
                'detalle' => $detalle,
                'total' => $total,
            ]);
        } catch (Throwable $e) {
            Log::error('AuditoriaService: fallo al registrar evento de auditoría', [
                'tipo_accion' => $tipoAccion,
                'tipo_operacion' => $tipoOperacion,
                'entidad_id' => $entidad->getKey(),
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
