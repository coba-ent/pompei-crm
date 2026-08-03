<?php

namespace App\Models\Integraciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borrador generado por IA para un mensaje entrante (spec 033,
 * data-model.md § `ml_sugerencias`).
 */
class MercadoLibreSugerencia extends Model
{
    protected $table = 'ml_sugerencias';

    protected $fillable = [
        'ml_mensaje_id', 'texto_sugerido', 'estado', 'error_mensaje', 'generada_en',
    ];

    protected $casts = [
        'generada_en' => 'datetime',
    ];

    public function mensaje(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreMensaje::class, 'ml_mensaje_id');
    }
}
