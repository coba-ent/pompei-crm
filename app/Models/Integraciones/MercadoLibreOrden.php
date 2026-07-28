<?php

namespace App\Models\Integraciones;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\EstadoOrden;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de venta sincronizada desde Mercado Libre (spec 012, data-model.md §2).
 * "Orden" = documento de Mercado Libre; "Venta" = documento del CRM.
 */
class MercadoLibreOrden extends Model
{
    protected $table = 'ml_ordenes';

    protected $fillable = [
        'ml_order_id', 'estado_ml', 'estado_orden', 'estado_conversion', 'motivo', 'motivo_detalle',
        'fecha_creada', 'fecha_cerrada', 'total', 'moneda',
        'comprador_ml_id', 'comprador_apodo', 'comprador_nombre', 'billing_info_id',
        'comprador_doc_tipo', 'comprador_doc_numero', 'comprador_condicion_iva', 'cliente_nuevo',
        'es_prueba', 'tiene_alerta_fraude', 'datos_faltantes',
        'venta_id', 'creacion_automatica', 'convertida_en', 'convertida_por',
        'payload', 'sincronizada_en',
    ];

    protected $casts = [
        'estado_orden' => EstadoOrden::class,
        'estado_conversion' => EstadoConversion::class,
        'motivo' => MotivoRequiereAtencion::class,
        'fecha_creada' => 'datetime',
        'fecha_cerrada' => 'datetime',
        'total' => 'decimal:2',
        'es_prueba' => 'boolean',
        'cliente_nuevo' => 'boolean',
        'tiene_alerta_fraude' => 'boolean',
        'creacion_automatica' => 'boolean',
        'convertida_en' => 'datetime',
        'payload' => 'array',
        'sincronizada_en' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MercadoLibreOrdenItem::class, 'ml_orden_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function convertidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convertida_por');
    }

    public function scopeEstadoConversion(Builder $query, EstadoConversion $estado): Builder
    {
        return $query->where('estado_conversion', $estado->value);
    }

    public function scopeConvertibles(Builder $query): Builder
    {
        return $query->where('estado_conversion', EstadoConversion::Lista->value);
    }

    public function estaConvertida(): bool
    {
        return $this->estado_conversion === EstadoConversion::Convertida;
    }
}
