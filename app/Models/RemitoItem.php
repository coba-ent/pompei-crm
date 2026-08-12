<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de un remito — snapshot de código/descripción, sin precio ni IVA (FR-012). */
class RemitoItem extends Model
{
    protected $table = 'remito_items';

    protected $fillable = ['remito_id', 'producto_id', 'codigo', 'descripcion', 'observacion', 'cantidad'];

    protected $casts = ['cantidad' => 'decimal:3'];

    public function remito(): BelongsTo
    {
        return $this->belongsTo(Remito::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
