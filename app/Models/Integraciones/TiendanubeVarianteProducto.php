<?php

namespace App\Models\Integraciones;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vinculación 1:1 entre una variante de Tiendanube y un producto del CRM
 * (spec 017, data-model.md §`tn_variante_producto`). Los índices únicos de la
 * migración son la garantía real de FR-022; esta clase no debe usarse como
 * único mecanismo de validación de cardinalidad.
 *
 * `tn_product_id`, campos de stock y de precio: spec 018, data-model.md
 * §`tn_variante_producto` (stock y precio).
 */
class TiendanubeVarianteProducto extends Model
{
    protected $table = 'tn_variante_producto';

    protected $fillable = [
        'variant_id', 'producto_id', 'nombre_variante_tn', 'vinculada_por', 'tn_product_id',
        'stock_pendiente', 'stock_sincronizado_en', 'stock_error', 'stock_error_en',
        'precio_pendiente', 'precio_sincronizado_en', 'precio_error', 'precio_error_en',
    ];

    protected $casts = [
        'stock_pendiente' => 'boolean',
        'stock_sincronizado_en' => 'datetime',
        'stock_error_en' => 'datetime',
        'precio_pendiente' => 'boolean',
        'precio_sincronizado_en' => 'datetime',
        'precio_error_en' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function vinculadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vinculada_por');
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('stock_pendiente', true);
    }

    public function scopePendientesPrecio(Builder $query): Builder
    {
        return $query->where('precio_pendiente', true);
    }
}
