<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaCreditoDebitoItem extends Model
{
    protected $table = 'nota_credito_debito_items';

    protected $fillable = [
        'nota_credito_debito_id', 'producto_id', 'cantidad', 'precio', 'descuento_pct', 'iva_pct', 'origen',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'precio' => 'decimal:2',
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
