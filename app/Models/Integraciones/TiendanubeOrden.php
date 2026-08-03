<?php

namespace App\Models\Integraciones;

use App\Enums\Tiendanube\EstadoConversion;
use App\Enums\Tiendanube\MotivoRequiereAtencion;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de venta sincronizada desde Tiendanube (spec 017, data-model.md
 * §`tn_ordenes`). "Orden" = documento de Tiendanube; "Venta" = documento del CRM.
 */
class TiendanubeOrden extends Model
{
    protected $table = 'tn_ordenes';

    protected $fillable = [
        'tn_order_id', 'status', 'payment_status', 'fulfillment_status',
        'estado_conversion', 'motivo', 'motivo_detalle',
        'fecha_creada', 'fecha_cerrada', 'total', 'moneda', 'storefront',
        'tn_customer_id', 'comprador_email', 'comprador_nombre', 'billing_document_number',
        'venta_id', 'creacion_automatica', 'convertida_en', 'convertida_por',
        'payload', 'sincronizada_en',
    ];

    protected $casts = [
        'estado_conversion' => EstadoConversion::class,
        'motivo' => MotivoRequiereAtencion::class,
        'fecha_creada' => 'datetime',
        'fecha_cerrada' => 'datetime',
        'total' => 'decimal:2',
        'creacion_automatica' => 'boolean',
        'convertida_en' => 'datetime',
        'payload' => 'array',
        'sincronizada_en' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TiendanubeOrdenItem::class, 'tn_orden_id');
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

    /** ¿Tiene una Venta asociada? Bloquea la eliminación física (spec 038). */
    public function tieneVentaAsociada(): bool
    {
        return ! is_null($this->venta_id);
    }

    /**
     * Elimina la fila sólo si no tiene Venta asociada (spec 038, R2): mismo
     * patrón que `CuentaTesoreria::tieneOperaciones()`, para que valga tanto
     * en el borrado manual actual como en un futuro endpoint/UI que lo reutilice.
     *
     * @throws \RuntimeException
     */
    public function eliminarSiSinVenta(): bool
    {
        if ($this->tieneVentaAsociada()) {
            throw new \RuntimeException('La orden tiene una Venta asociada; desvinculá o eliminá la Venta antes de borrarla.');
        }

        return (bool) $this->delete();
    }
}
