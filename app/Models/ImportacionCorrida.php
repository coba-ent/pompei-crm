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
        'archivo_guardado_ruta',
        'archivo_guardado_en',
        'archivo_vencido_en',
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
        'archivo_guardado_en' => 'datetime',
        'archivo_vencido_en' => 'datetime',
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

    /**
     * 'disponible' | 'nunca_guardado' | 'vencido' (spec 093, FR-015). Se DERIVA de las columnas,
     * nunca se persiste: tres estados y no un booleano porque "no está porque venció" y "no está
     * porque nunca se guardó" le dicen cosas distintas a quien audita.
     */
    public function estadoArchivo(): string
    {
        if ($this->archivo_vencido_en !== null) {
            return 'vencido';
        }

        return $this->archivo_guardado_ruta !== null ? 'disponible' : 'nunca_guardado';
    }

    /** ¿Hay filas de snapshot para armar el informe de cambios? (FR-007) */
    public function tieneInforme(): bool
    {
        return $this->filas()->exists();
    }
}
