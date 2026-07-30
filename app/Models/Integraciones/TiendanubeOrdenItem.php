<?php

namespace App\Models\Integraciones;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una orden de Tiendanube — cada variante vendida (spec 017, data-model.md §`tn_orden_items`). */
class TiendanubeOrdenItem extends Model
{
    protected $table = 'tn_orden_items';

    protected $fillable = [
        'tn_orden_id', 'tn_product_id', 'variant_id', 'nombre_producto', 'nombre_variante', 'sku',
        'cantidad', 'precio_unitario', 'total_linea', 'producto_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precio_unitario' => 'decimal:2',
        'total_linea' => 'decimal:2',
    ];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(TiendanubeOrden::class, 'tn_orden_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
