<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Encabezado mínimo (FR-018); detalle de ítems pendiente de relevamiento propio. */
class Remito extends Model
{
    protected $table = 'remitos';

    protected $fillable = ['venta_id', 'compra_id', 'fecha', 'nro_remito'];

    protected $casts = ['fecha' => 'date'];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public static function siguienteNumero(): string
    {
        return (string) ((int) static::max('id') + 1);
    }
}
