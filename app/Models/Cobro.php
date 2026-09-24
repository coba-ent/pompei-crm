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

    protected $fillable = ['venta_id', 'fecha', 'cuenta_tesoreria_id', 'monto', 'nota', 'vuelto', 'cuenta_vuelto_id'];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'vuelto' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cuentaTesoreria(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class);
    }

    /** Cuenta de la que salió el vuelto (spec 110). Null en cobros sin vuelto. */
    public function cuentaVuelto(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class, 'cuenta_vuelto_id');
    }

    /**
     * Movimiento de INGRESO del cobro.
     *
     * **El filtro por tipo no es opcional (spec 110).** Desde que un cobro puede tener dos
     * movimientos con el mismo `origen` —el ingreso (`cobro`) y el vuelto (`vuelto`)—, un
     * `morphOne` sin filtrar devuelve cualquiera de los dos, de forma no determinística. Si
     * devolviera el del vuelto, `Cobranzas::anularCobro()` borraría el vuelto y **dejaría el
     * ingreso vivo en la cuenta**: exactamente el saldo fantasma que el docblock de ese método
     * documenta como incidente previo.
     *
     * Es retrocompatible: los cobros anteriores a la spec 110 sólo tienen movimientos `cobro`.
     */
    public function movimientoTesoreria(): MorphOne
    {
        return $this->morphOne(MovimientoTesoreria::class, 'origen')->where('tipo', 'cobro');
    }

    /** Movimiento de EGRESO por el vuelto (spec 110). Null en cobros sin vuelto. */
    public function movimientoVuelto(): MorphOne
    {
        return $this->morphOne(MovimientoTesoreria::class, 'origen')->where('tipo', 'vuelto');
    }

    /**
     * Importe que el cliente entregó realmente.
     *
     * `monto` guarda el **neto imputado a la venta**, no lo recibido — ver la migración
     * `add_vuelto_to_cobros_table` y `docs/modelo_datos.md`. Lo recibido es `monto + vuelto`.
     */
    public function recibido(): float
    {
        return round((float) $this->monto + (float) ($this->vuelto ?? 0), 2);
    }

    /** ¿Esta cobranza entregó vuelto? (spec 110) */
    public function tieneVuelto(): bool
    {
        return (float) ($this->vuelto ?? 0) > 0;
    }
}
