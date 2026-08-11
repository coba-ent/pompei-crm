<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    /**
     * Próximo N° de remito. Se deriva del propio `nro_remito` y no del `id`, por el mismo motivo que
     * en Presupuesto: atarlo al auto_increment corre la numeración con cada importación histórica.
     * El CAST es necesario porque la columna es varchar y sin él el máximo saldría alfabético
     * ("9" > "10").
     */
    public static function siguienteNumero(): string
    {
        return (string) ((int) static::query()->max(DB::raw('CAST(nro_remito AS UNSIGNED)')) + 1);
    }
}
