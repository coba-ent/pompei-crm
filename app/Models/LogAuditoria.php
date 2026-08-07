<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro inmutable de auditoría (spec 054) — sólo INSERT vía AuditoriaService::registrarEvento(). */
class LogAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'logs_auditoria';

    protected $fillable = [
        'usuario_id', 'usuario_nombre', 'origen_sistema', 'tipo_accion', 'tipo_operacion',
        'entidad_tipo', 'entidad_id', 'detalle', 'total',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function scopeDeId(Builder $query, int $id): Builder
    {
        return $query->where('id', $id);
    }

    public function scopeDeOperacion(Builder $query, string $operacion): Builder
    {
        return $query->where('tipo_operacion', $operacion);
    }

    public function scopeDeUsuario(Builder $query, int $usuarioId): Builder
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopeEntreFechas(Builder $query, string $desde, string $hasta): Builder
    {
        return $query->whereBetween('created_at', ["{$desde} 00:00:00", "{$hasta} 23:59:59"]);
    }
}
