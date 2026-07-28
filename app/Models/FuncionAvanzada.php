<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuncionAvanzada extends Model
{
    protected $table = 'funciones_avanzadas';

    protected $fillable = [
        'clave', 'nombre', 'descripcion', 'icono', 'orden', 'disponible',
        'activa', 'ruta_configuracion', 'actualizada_por', 'actualizada_en',
    ];

    protected $casts = [
        'disponible' => 'boolean',
        'activa' => 'boolean',
        'actualizada_en' => 'datetime',
    ];

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por');
    }

    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderBy('orden');
    }
}
