<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Percepción / Impuesto Interno / Interés — data-model.md. */
class PresupuestoConcepto extends Model
{
    protected $table = 'presupuesto_conceptos';

    protected $fillable = ['presupuesto_id', 'tipo', 'concepto', 'monto'];

    protected $casts = ['monto' => 'decimal:2'];

    public function presupuesto(): BelongsTo
    {
        return $this->belongsTo(Presupuesto::class);
    }
}
