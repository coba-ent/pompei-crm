<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoVariante extends Model
{
    protected $table = 'producto_variantes';

    protected $fillable = [
        'producto_id', 'sku', 'talle', 'color', 'nombre', 'precio_extra', 'activo',
    ];

    protected $casts = [
        'precio_extra' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'variante_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'variante_id');
    }

    /**
     * ¿La variante tiene operaciones asociadas (movimientos de stock)?
     * Bloquea la eliminación física cuando es true (FR-012).
     */
    public function tieneOperaciones(): bool
    {
        return $this->movimientos()->exists();
    }
}
