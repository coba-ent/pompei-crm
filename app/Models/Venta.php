<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Integraciones\MercadoLibreOrden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ventas';

    protected $fillable = [
        'presupuesto_id', 'origen', 'cliente_id', 'categoria_id', 'lista_precio_id', 'deposito_id',
        'fecha_emision', 'fecha_validez', 'servicio_desde', 'servicio_hasta',
        'tipo_comprobante', 'nro_comprobante', 'fecha_vto_cobro',
        'descuento_general_pct', 'descuento_general_tipo', 'descuento_general_monto', 'subtotal_sin_descuento', 'descuento',
        'subtotal_con_descuento', 'total', 'nota_cliente', 'nota_interna',
        'formas_pago', 'metodos_envio', 'vendedor_id', 'creado_por_id', 'submit_token', 'legacy_id',
        'ml_order_id', 'tn_order_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_validez' => 'date',
        'servicio_desde' => 'date',
        'servicio_hasta' => 'date',
        'fecha_vto_cobro' => 'date',
        'descuento_general_pct' => 'decimal:2',
        'descuento_general_monto' => 'decimal:2',
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

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class, 'vendedor_id');
    }

    /** Usuario que cargó la Venta — distinto de "Vendedor" (ver modelo_datos.md §ventas). */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    /** Movimientos de stock generados por esta Venta (StockDeVenta usa $venta como $origen morph). */
    public function movimientosStock(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MovimientoStock::class, 'origen');
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

    /**
     * Comprobante fiscal **vigente**: el aprobado si existe, y si no el último intento.
     *
     * Una Venta puede acumular varios `ComprobanteFiscal` (cada rechazo de ARCA deja el suyo, que se
     * conserva como evidencia). Sin este orden explícito, un `morphOne` pelado devuelve un intento
     * cualquiera —en la práctica el rechazo más viejo, sin CAE ni número—, y como de esta relación
     * dependen `estaFacturada()`, `puedeEnviarseAArca()` y el PDF, la Venta quedaba reenviable,
     * editable y sin CAE en el comprobante pese a tener CAE aprobado (incidente 14/08/2026, Venta
     * 24447). Para filtrar por el historial completo usar `comprobantesFiscales()`.
     */
    public function comprobanteFiscal(): MorphOne
    {
        return $this->morphOne(ComprobanteFiscal::class, 'comprobantable')
            ->orderByRaw("CASE WHEN estado = 'aprobado' THEN 0 ELSE 1 END")
            ->orderByDesc('id');
    }

    /** Historial completo de intentos contra ARCA, incluidos los rechazados. */
    public function comprobantesFiscales(): MorphMany
    {
        return $this->morphMany(ComprobanteFiscal::class, 'comprobantable');
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

    /** Ya tiene CAE aprobado de ARCA: no se puede editar (los datos ya fueron declarados al fisco). */
    public function estaFacturada(): bool
    {
        $comprobante = $this->relationLoaded('comprobanteFiscal') ? $this->comprobanteFiscal : $this->comprobanteFiscal()->first();

        return (bool) ($comprobante && $comprobante->estado === 'aprobado');
    }

    /**
     * Prefijo de la serie interna, sin significado fiscal (research.md §5).
     *
     * Vive en un espacio de nombres separado a propósito: la numeración fiscal real la asigna ARCA
     * sobre el punto de venta configurado (hoy el 9) y se guarda al aprobarse el comprobante, así
     * que la serie interna nunca puede colisionar con ella.
     */
    public const PREFIJO_SERIE_INTERNA = '0001-';

    /**
     * N° de comprobante correlativo simple por tipo (dato sin emisión fiscal — research.md §5).
     *
     * Toma el máximo de la serie en vez de contar filas: contar rompía de dos formas — al importar
     * el histórico el contador saltaba tantas posiciones como ventas importadas, y al borrar una
     * venta el número siguiente se repetía. El filtro por prefijo es imprescindible porque conviven
     * en la columna los números fiscales reales (`0009-…` de ARCA, `0005-…` del sistema viejo), que
     * ordenan por encima de la serie interna y darían un máximo equivocado.
     */
    public static function siguienteNroComprobante(string $tipoComprobante): string
    {
        $ultimo = static::withTrashed()
            ->where('tipo_comprobante', $tipoComprobante)
            ->where('nro_comprobante', 'like', self::PREFIJO_SERIE_INTERNA.'%')
            ->max('nro_comprobante');

        $n = $ultimo === null
            ? 1
            : (int) substr($ultimo, strlen(self::PREFIJO_SERIE_INTERNA)) + 1;

        return self::PREFIJO_SERIE_INTERNA.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }
}
