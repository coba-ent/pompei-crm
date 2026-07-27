<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraConcepto extends Model
{
    protected $table = 'compra_conceptos';

    protected $fillable = ['compra_id', 'tipo', 'concepto', 'monto'];

    protected $casts = ['monto' => 'decimal:2'];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }
}
