<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaConcepto extends Model
{
    protected $table = 'venta_conceptos';

    protected $fillable = ['venta_id', 'tipo', 'concepto', 'monto'];

    protected $casts = ['monto' => 'decimal:2'];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
