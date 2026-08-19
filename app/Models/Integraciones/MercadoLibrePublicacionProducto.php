<?php

namespace App\Models\Integraciones;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vinculación 1:1 entre una publicación de Mercado Libre y un producto del CRM
 * (spec 012, data-model.md §4). Infraestructura compartida con la spec 013.
 * Los índices únicos de la migración son la garantía real de FR-022; esta
 * clase no debe usarse como único mecanismo de validación de cardinalidad.
 */
class MercadoLibrePublicacionProducto extends Model
{
    protected $table = 'ml_publicacion_producto';

    protected $fillable = [
        'ml_item_id', 'producto_id', 'titulo_ml', 'vinculada_por',
        'stock_pendiente', 'stock_sincronizado_en', 'stock_error', 'stock_error_en',
        'stock_intentos_fallidos', 'stock_error_desde', 'stock_requiere_intervencion', 'ultimo_stock_publicado',
        'precio_pendiente', 'precio_sincronizado_en', 'precio_error', 'precio_error_en',
        'listing_type_id', 'listing_type_sincronizado_en',
        'logistic_type', 'inventory_id', 'user_product_id', 'logistica_sincronizada_en',
    ];

    /**
     * Traducción a español del tipo de logística crudo de Mercado Libre (spec 065,
     * contracts/rutas-internas.md §1). Vive en el modelo porque es presentación pura:
     * el dominio nunca compara contra estas etiquetas, sólo contra el valor crudo.
     */
    public const ETIQUETAS_LOGISTICA = [
        'fulfillment' => 'Full',
        'xd_drop_off' => 'Colecta',
        'self_service' => 'Flex',
        'custom' => 'A cargo del vendedor',
        'not_specified' => 'Sin especificar',
    ];

    /** Valor crudo de `shipping.logistic_type` que identifica a Full (spec 065, research R1). */
    public const LOGISTICA_FULL = 'fulfillment';

    protected $casts = [
        'stock_pendiente' => 'boolean',
        'stock_sincronizado_en' => 'datetime',
        'stock_error_en' => 'datetime',
        'stock_intentos_fallidos' => 'integer',
        'stock_error_desde' => 'datetime',
        'stock_requiere_intervencion' => 'boolean',
        'ultimo_stock_publicado' => 'integer',
        'precio_pendiente' => 'boolean',
        'precio_sincronizado_en' => 'datetime',
        'precio_error_en' => 'datetime',
        'listing_type_sincronizado_en' => 'datetime',
        'logistica_sincronizada_en' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function vinculadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vinculada_por');
    }

    /**
     * Vínculos con un cambio de stock sin empujar todavía a Mercado Libre (spec 013, FR-003).
     * spec 063/FR-016: excluye las bloqueadas por error permanente — ahí está el ahorro de las
     * ~305 llamadas fallidas cada 6 h (SC-004); se reactivan a mano (T025).
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('stock_pendiente', true)->where('stock_requiere_intervencion', false);
    }

    /** spec 063/FR-014: publicaciones cuya sincronización de stock está fallando y ya requieren intervención. */
    public function scopeRequierenIntervencion(Builder $query): Builder
    {
        return $query->where('stock_requiere_intervencion', true);
    }

    /** Vínculos con un cambio de precio sin empujar todavía a Mercado Libre (spec 016, FR-014). */
    public function scopePendientesPrecio(Builder $query): Builder
    {
        return $query->where('precio_pendiente', true);
    }

    /**
     * Único lugar de la app que traduce el tipo crudo informado por Mercado
     * Libre a "es Premium" (spec 050, research.md R2) — `gold_pro` es Premium,
     * cualquier otro valor (incluido `null`, sin clasificar todavía) no lo es.
     */
    public function esPremium(): bool
    {
        return $this->listing_type_id === 'gold_pro';
    }

    /**
     * Único lugar de la app que traduce el tipo de logística crudo a "está en Full"
     * (spec 065, data-model §Reglas de derivación). Cualquier otro valor —incluido
     * `null`, sin clasificar todavía— es no-Full: ante la duda nunca se asume Full,
     * porque asumirlo de más frenaría el envío de stock de una publicación que sí
     * lo necesita (FR-005).
     */
    public function esFull(): bool
    {
        return $this->logistic_type === self::LOGISTICA_FULL;
    }

    /**
     * Vínculos alojados en el centro de distribución de Mercado Libre (spec 065).
     *
     * Se llama `soloFull` y no `esFull` a propósito: un `scopeEsFull` nunca sería
     * alcanzable, porque `Model::esFull()` resuelve primero al método de instancia
     * de arriba y explota con "cannot be called statically".
     */
    public function scopeSoloFull(Builder $query): Builder
    {
        return $query->where('logistic_type', self::LOGISTICA_FULL);
    }

    /** Vínculos de logística propia o todavía sin clasificar — `null` cuenta acá (FR-005). */
    public function scopeNoFull(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('logistic_type', '!=', self::LOGISTICA_FULL)->orWhereNull('logistic_type');
        });
    }

    /**
     * Etiqueta legible del tipo de logística (FR-024). Un valor que Mercado Libre
     * agregue en el futuro se muestra **tal cual** en vez de descartarse (FR-005a):
     * es preferible un texto crudo visible a una publicación que parece sin clasificar.
     */
    public function getLogisticaEtiquetaAttribute(): string
    {
        if ($this->logistic_type === null || $this->logistic_type === '') {
            return 'Sin clasificar';
        }

        return self::ETIQUETAS_LOGISTICA[$this->logistic_type] ?? $this->logistic_type;
    }
}
