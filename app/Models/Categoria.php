<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasFactory;

    protected $table = 'categorias';

    protected $fillable = ['tipo', 'categoria_padre_id', 'nombre', 'es_sistema', 'activo'];

    protected $casts = [
        'es_sistema' => 'boolean',
        'activo' => 'boolean',
    ];

    /** Categoría padre (jerarquía Categoría→Subcategoría de Gasto, research.md §7). */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_padre_id');
    }

    public function scopeVenta(Builder $query): Builder
    {
        return $query->where('tipo', 'venta');
    }

    public function scopeCompra(Builder $query): Builder
    {
        return $query->where('tipo', 'compra');
    }

    public function scopeGasto(Builder $query): Builder
    {
        return $query->where('tipo', 'gasto');
    }

    public function scopeDeIngreso(Builder $query): Builder
    {
        return $query->where('tipo', 'ingreso');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
