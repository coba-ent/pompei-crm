<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuntoVenta extends Model
{
    protected $table = 'puntos_venta';

    protected $fillable = [
        'numero', 'descripcion', 'tipo_ws', 'por_defecto', 'activo',
    ];

    protected $casts = [
        'numero' => 'integer',
        'por_defecto' => 'boolean',
        'activo' => 'boolean',
    ];

    public function comprobantesFiscales(): HasMany
    {
        return $this->hasMany(ComprobanteFiscal::class);
    }

    public static function activoPorDefecto(): ?self
    {
        return static::query()->where('activo', true)->where('por_defecto', true)->first();
    }
}
