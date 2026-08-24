<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaCreditoDebitoItem extends Model
{
    protected $table = 'nota_credito_debito_items';

    protected $fillable = [
        'nota_credito_debito_id', 'producto_id', 'cantidad', 'precio', 'costo_unitario', 'descuento_pct', 'iva_pct', 'origen',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'precio' => 'decimal:2',
        // Costo congelado de la línea (spec 075), siempre en positivo: el signo de la nota lo
        // aporta la cantidad, no el costo. `null` = sin congelar ⇒ fallback al promedio de compras.
        'costo_unitario' => 'decimal:2',
        'descuento_pct' => 'decimal:2',
        'iva_pct' => 'decimal:2',
    ];

    public function notaCreditoDebito(): BelongsTo
    {
        return $this->belongsTo(NotaCreditoDebito::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
