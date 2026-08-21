<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aplicación de saldo a favor de un comprobante a otro del mismo cliente/proveedor (spec 072).
 *
 * NO es un cobro ni un pago: no toca Tesorería. Ver `App\Services\Ingresos\CreditoCliente`.
 */
class AplicacionCredito extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'aplicaciones_credito';

    protected $fillable = [
        'origen_type', 'origen_id', 'destino_type', 'destino_id',
        'nota_credito_debito_id', 'monto', 'fecha', 'nota', 'usuario_id',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    /** Comprobante que cede el crédito (el que tiene la Nota de Crédito). */
    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    /** Comprobante que recibe el crédito. */
    public function destino(): MorphTo
    {
        return $this->morphTo();
    }

    public function notaCreditoDebito(): BelongsTo
    {
        return $this->belongsTo(NotaCreditoDebito::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
