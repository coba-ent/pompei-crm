<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Integraciones\MercadoLibreOrden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ventas';

    protected $fillable = [
        'presupuesto_id', 'origen', 'cliente_id', 'categoria_id', 'lista_precio_id',
        'fecha_emision', 'fecha_validez', 'servicio_desde', 'servicio_hasta',
        'tipo_comprobante', 'nro_comprobante', 'fecha_vto_cobro',
        'descuento_general_pct', 'subtotal_sin_descuento', 'descuento',
        'subtotal_con_descuento', 'total', 'nota_cliente', 'nota_interna',
        'formas_pago', 'metodos_envio', 'vendedor_id', 'submit_token', 'legacy_id',
        'ml_order_id', 'tn_order_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_validez' => 'date',
        'servicio_desde' => 'date',
        'servicio_hasta' => 'date',
        'fecha_vto_cobro' => 'date',
        'descuento_general_pct' => 'decimal:2',
        'subtotal_sin_descuento' => 'decimal:2',
        'descuento' => 'decimal:2',
        'subtotal_con_descuento' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class, 'vendedor_id');
    }

    public function presupuesto(): BelongsTo
    {
        return $this->belongsTo(Presupuesto::class);
    }

    /** Orden de Mercado Libre que originó esta Venta, si la hay (FR-033). */
    public function mlOrden(): HasOne
    {
        return $this->hasOne(MercadoLibreOrden::class, 'venta_id');
    }

    public function scopeOrigen(Builder $query, string $origen): Builder
    {
        return $query->where('origen', $origen);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(VentaConcepto::class);
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class);
    }

    public function notasCreditoDebito(): HasMany
    {
        return $this->hasMany(NotaCreditoDebito::class);
    }

    public function remitos(): HasMany
    {
        return $this->hasMany(Remito::class);
    }

    public function etiquetas(): MorphToMany
    {
        return $this->morphToMany(Etiqueta::class, 'etiquetable');
    }

    public function comprobanteFiscal(): MorphOne
    {
        return $this->morphOne(ComprobanteFiscal::class, 'comprobantable');
    }

    /** Cobrado = Σ cobros no anulados (derivado, data-model.md §Cálculos clave). */
    public function cobrado(): float
    {
        return (float) $this->cobros()->sum('monto');
    }

    public function totalNotasCredito(): float
    {
        return (float) $this->notasCreditoDebito()->where('tipo', 'credito')->sum('monto');
    }

    public function totalNotasDebito(): float
    {
        return (float) $this->notasCreditoDebito()->where('tipo', 'debito')->sum('monto');
    }

    /** A Cobrar = Total + Σ ND − Σ NC − Cobrado (derivado, nunca almacenado). */
    public function aCobrar(): float
    {
        return round((float) $this->total + $this->totalNotasDebito() - $this->totalNotasCredito() - $this->cobrado(), 2);
    }

    /** Estado de cobro derivado: sin_cobrar / parcial / cobrada. */
    public function estadoCobro(): string
    {
        $cobrado = $this->cobrado();

        if ($cobrado <= 0) {
            return 'sin_cobrar';
        }

        return $this->aCobrar() > 0.005 ? 'parcial' : 'cobrada';
    }

    /**
     * Habilita la acción manual "Enviar a ARCA" (spec 040): tipo A/B/C, todavía sin CAE aprobado, y
     * con la Función Avanzada de Facturación Electrónica activa.
     */
    public function puedeEnviarseAArca(): bool
    {
        if (! in_array($this->tipo_comprobante, ['A', 'B', 'C'], true)) {
            return false;
        }

        if (! FuncionAvanzada::activa('facturacion_electronica')) {
            return false;
        }

        $comprobante = $this->relationLoaded('comprobanteFiscal') ? $this->comprobanteFiscal : $this->comprobanteFiscal()->first();

        return ! ($comprobante && $comprobante->estado === 'aprobado');
    }

    /** N° de comprobante correlativo simple por tipo (dato sin emisión fiscal — research.md §5). */
    public static function siguienteNroComprobante(string $tipoComprobante): string
    {
        $n = static::withTrashed()->where('tipo_comprobante', $tipoComprobante)->count() + 1;

        return '0001-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }
}
