<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pago extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pagos';

    protected $fillable = ['compra_id', 'fecha', 'cuenta_tesoreria_id', 'monto', 'nota', 'nro_comprobante'];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function cuentaTesoreria(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class);
    }

    public function movimientoTesoreria(): MorphOne
    {
        return $this->morphOne(MovimientoTesoreria::class, 'origen');
    }

    public function retenciones(): HasMany
    {
        return $this->hasMany(Retencion::class);
    }
}
