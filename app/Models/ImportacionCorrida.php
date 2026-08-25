<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una corrida confirmada del Paso 3 del asistente de Importar Datos — sólo
 * solapa Productos & Servicios (spec 078). Habilita "Deshacer import" dentro
 * de una ventana de 48hs desde `confirmado_en`.
 */
class ImportacionCorrida extends Model
{
    protected $table = 'importacion_corridas';

    protected $fillable = [
        'entidad',
        'usuario_id',
        'archivo_original',
        'confirmado_en',
        'deshacer_disponible_hasta',
        'filas_creadas',
        'filas_actualizadas',
        'filas_fallidas',
        'deshecho_en',
        'deshecho_por_id',
        'filas_revertidas',
        'filas_no_revertidas',
    ];

    protected $casts = [
        'confirmado_en' => 'datetime',
        'deshacer_disponible_hasta' => 'datetime',
        'deshecho_en' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function deshechoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deshecho_por_id');
    }

    public function filas(): HasMany
    {
        return $this->hasMany(ImportacionFilaSnapshot::class, 'importacion_corrida_id');
    }

    /** 'vigente' | 'deshecho' | 'vencido' — nunca persistido, siempre calculado contra now(). */
    public function estado(): string
    {
        if ($this->deshecho_en !== null) {
            return 'deshecho';
        }

        return now()->lt($this->deshacer_disponible_hasta) ? 'vigente' : 'vencido';
    }

    public function puedeDeshacer(): bool
    {
        return $this->estado() === 'vigente';
    }
}
