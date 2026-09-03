<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraItem extends Model
{
    protected $table = 'compra_items';

    protected $fillable = [
        'compra_id', 'producto_id', 'descripcion', 'cantidad',
        'precio_unitario', 'descuento_pct', 'iva_pct', 'subtotal', 'subtotal_con_iva',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'precio_unitario' => 'decimal:2',
        'descuento_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'subtotal_con_iva' => 'decimal:2',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * % de bonificación EFECTIVO de la línea: el de línea combinado con el descuento general del
     * comprobante, tal como lo muestra Contagram en la columna "Bonif." — ahí no distingue origen,
     * muestra cuánto bajó realmente el precio de esa línea (spec 098).
     *
     * `subtotal` ya sale de `CalculoComprobante` con ambos descuentos aplicados (línea × general),
     * así que se reconstruye el % comparando contra el bruto (cantidad × precio unitario) en lugar
     * de sumar los dos porcentajes — sumarlos sería incorrecto porque no son aditivos (un 10% de
     * línea + 10% general no da 20%, da 1 - 0.9*0.9 = 19%).
     */
    public function bonifEfectivaPct(): float
    {
        $bruto = (float) $this->cantidad * (float) $this->precio_unitario;

        if ($bruto <= 0) {
            return 0.0;
        }

        return round((1 - ((float) $this->subtotal / $bruto)) * 100, 2);
    }

    /** Bonif. efectiva lista para imprimir: "10%", "12,5%" o "-" si no hay descuento. */
    public function bonifEfectivaEtiqueta(): string
    {
        $pct = $this->bonifEfectivaPct();

        if ($pct <= 0) {
            return '-';
        }

        return rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',').'%';
    }
}
