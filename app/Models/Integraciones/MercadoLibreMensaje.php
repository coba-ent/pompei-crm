<?php

namespace App\Models\Integraciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entrada individual dentro de una conversación de Mensajería (spec 032,
 * data-model.md § `ml_mensajes`). `ml_id` es la clave natural de Mercado
 * Libre usada para idempotencia del webhook (FR-004).
 */
class MercadoLibreMensaje extends Model
{
    protected $table = 'ml_mensajes';

    protected $fillable = [
        'ml_conversacion_id', 'ml_id', 'origen', 'texto', 'enviado_en',
    ];

    protected $casts = [
        'enviado_en' => 'datetime',
    ];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreConversacion::class, 'ml_conversacion_id');
    }

    public function respuestaExitosa(): HasOne
    {
        return $this->hasOne(MercadoLibreRespuestaEnviada::class, 'ml_mensaje_id')->where('resultado', 'exito');
    }
}
