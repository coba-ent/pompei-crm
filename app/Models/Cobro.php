<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cobro extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cobros';

    protected $fillable = ['venta_id', 'fecha', 'cuenta_tesoreria_id', 'monto', 'nota'];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cuentaTesoreria(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class);
    }

    public function movimientoTesoreria(): MorphOne
    {
        return $this->morphOne(MovimientoTesoreria::class, 'origen');
    }
}
