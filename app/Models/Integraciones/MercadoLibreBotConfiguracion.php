<?php

namespace App\Models\Integraciones;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro único (single-tenant) con la configuración del bot de sugerencias
 * (spec 033, data-model.md § `ml_bot_configuracion`). Acceder siempre por
 * actual(), mismo patrón que `MercadoLibreConfiguracion`.
 */
class MercadoLibreBotConfiguracion extends Model
{
    protected $table = 'ml_bot_configuracion';

    protected $fillable = [
        'instrucciones_tono', 'actualizada_por', 'actualizada_en',
    ];

    protected $casts = [
        'actualizada_en' => 'datetime',
    ];

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por');
    }

    public static function actual(): self
    {
        $configuracion = static::query()->first();

        if (! $configuracion) {
            $configuracion = new static();
            $configuracion->id = 1;
            $configuracion->save();
        }

        return $configuracion;
    }
}
