<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Remito (spec 064): documento logístico, no mueve stock ni dinero (FR-010). */
class Remito extends Model
{
    protected $table = 'remitos';

    protected $fillable = [
        'venta_id', 'compra_id', 'fecha', 'nro_remito',
        'transportista_id', 'domicilio_entrega', 'nota', 'monto_asegurado', 'tipo',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto_asegurado' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // FR-018: al eliminar la Venta/Compra de origen, sus remitos se eliminan con ella. El
        // borrado es real (no soft delete — FR-017), así que el `cascadeOnDelete` de la FK no
        // alcanza porque Venta/Compra usan soft delete.
        static::deleting(function (Remito $remito) {
            $remito->items()->delete();
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemitoItem::class);
    }

    public function transportista(): BelongsTo
    {
        return $this->belongsTo(Transportista::class);
    }

    /** Se deriva de la suma de las líneas; no se persiste (data-model.md). */
    public function totalBultos(): float
    {
        return (float) $this->items->sum('cantidad');
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
