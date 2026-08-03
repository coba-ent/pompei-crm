<?php

namespace App\Models\Integraciones;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoría de una respuesta efectivamente enviada a un comprador de
 * Mercado Libre (spec 032, data-model.md § `ml_respuestas_enviadas`, FR-006).
 */
class MercadoLibreRespuestaEnviada extends Model
{
    protected $table = 'ml_respuestas_enviadas';

    protected $fillable = [
        'ml_mensaje_id', 'texto_enviado', 'usuario_id', 'enviado_en', 'resultado', 'error_mensaje',
        'ml_sugerencia_id', 'sugerencia_editada',
    ];

    protected $casts = [
        'enviado_en' => 'datetime',
        'sugerencia_editada' => 'boolean',
    ];

    public function mensaje(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreMensaje::class, 'ml_mensaje_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function sugerencia(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreSugerencia::class, 'ml_sugerencia_id');
    }
}
