<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compra extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'compras';

    protected $fillable = [
        'legacy_id',
        'proveedor_id', 'creado_por_id', 'categoria_id', 'deposito_id', 'tipo_comprobante', 'nro_comprobante',
        'fecha_emision', 'fecha_vto_pago', 'servicio_desde', 'servicio_hasta',
        'mes_imputacion_iva', 'subtotal_sin_descuento', 'descuento_general_pct', 'descuento',
        'subtotal_con_descuento', 'total', 'nota_interna', 'submit_token',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vto_pago' => 'date',
        'servicio_desde' => 'date',
        'servicio_hasta' => 'date',
        'mes_imputacion_iva' => 'date',
        'subtotal_sin_descuento' => 'decimal:2',
        'descuento_general_pct' => 'decimal:2',
        'descuento' => 'decimal:2',
        'subtotal_con_descuento' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompraItem::class);
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(CompraConcepto::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function notasCreditoDebito(): HasMany
    {
        return $this->hasMany(NotaCreditoDebito::class);
    }

    public function remitos(): HasMany
    {
        return $this->hasMany(Remito::class);
    }

    public function comprobanteFiscal(): MorphOne
    {
        return $this->morphOne(ComprobanteFiscal::class, 'comprobantable');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    public function etiquetas(): MorphToMany
    {
        return $this->morphToMany(Etiqueta::class, 'etiquetable');
    }

    /** Pagado = Σ pagos no anulados (derivado, data-model.md §Cálculos clave). */
    public function pagado(): float
    {
        return (float) $this->pagos()->sum('monto');
    }

    public function totalNotasCredito(): float
    {
        return (float) $this->notasCreditoDebito()->where('tipo', 'credito')->sum('monto');
    }

    public function totalNotasDebito(): float
    {
        return (float) $this->notasCreditoDebito()->where('tipo', 'debito')->sum('monto');
    }

    /** A Pagar = Total + Σ ND − Σ NC − Pagado (derivado, nunca almacenado — Clarifications). */
    public function aPagar(): float
    {
        return round((float) $this->total + $this->totalNotasDebito() - $this->totalNotasCredito() - $this->pagado(), 2);
    }

    /** Estado de pago derivado: a_pagar / parcial / pagado. Nunca forzable (Clarifications). */
    public function estadoPago(): string
    {
        $pagado = $this->pagado();

        if ($pagado <= 0) {
            return 'a_pagar';
        }

        return $this->aPagar() > 0.005 ? 'parcial' : 'pagado';
    }

    /** N° de comprobante correlativo simple por tipo (dato sin emisión fiscal — research.md §9). */
    public static function siguienteNroComprobante(string $tipoComprobante): string
    {
        $n = static::withTrashed()->where('tipo_comprobante', $tipoComprobante)->count() + 1;

        return '0001-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }
}
