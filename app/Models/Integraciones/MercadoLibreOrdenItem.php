<?php

namespace App\Models\Integraciones;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una orden de Mercado Libre (spec 012, data-model.md §3). */
class MercadoLibreOrdenItem extends Model
{
    protected $table = 'ml_orden_items';

    protected $fillable = [
        'ml_orden_id', 'ml_item_id', 'ml_variation_id', 'titulo', 'sku_vendedor',
        'cantidad', 'precio_unitario', 'precio_bruto', 'comision_ml', 'total_linea', 'producto_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precio_unitario' => 'decimal:2',
        'precio_bruto' => 'decimal:2',
        'comision_ml' => 'decimal:2',
        'total_linea' => 'decimal:2',
    ];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreOrden::class, 'ml_orden_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** No nulo ⇒ publicación con variantes, no soportado (FR-027). */
    public function tieneVariante(): bool
    {
        return filled($this->ml_variation_id);
    }
}
