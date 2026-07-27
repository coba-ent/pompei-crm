<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class OtroIngreso extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'otros_ingresos';

    protected $fillable = [
        'fecha', 'monto', 'categoria_id', 'cuenta_tesoreria_id',
        'descripcion', 'pendiente', 'usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'pendiente' => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function cuentaTesoreria(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function movimientoTesoreria(): MorphOne
    {
        return $this->morphOne(MovimientoTesoreria::class, 'origen');
    }

    /** Estado visual del listado (columna "Estado"): Pendiente / Cobrado. */
    public function estado(): string
    {
        return $this->pendiente ? 'pendiente' : 'cobrado';
    }
}
