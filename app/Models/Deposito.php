<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Deposito extends Model
{
    protected $table = 'depositos';

    protected $fillable = ['nombre', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * ¿El depósito tiene stock o movimientos asociados? Bloquea la eliminación
     * física (FR-005), mismo patrón que Cliente/Proveedor/Producto::tieneOperaciones().
     */
    public function tieneOperaciones(): bool
    {
        return $this->stocks()->where('cantidad', '!=', 0)->exists()
            || $this->movimientos()->exists();
    }

    public function stocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Stock::class, 'deposito_id');
    }

    public function movimientos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'deposito_id');
    }
}
