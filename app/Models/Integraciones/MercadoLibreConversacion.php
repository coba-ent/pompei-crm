<?php

namespace App\Models\Integraciones;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hilo de intercambio con un comprador de Mercado Libre — Pregunta pre-venta
 * o Mensajería post-venta (spec 032, data-model.md § `ml_conversaciones`).
 */
class MercadoLibreConversacion extends Model
{
    protected $table = 'ml_conversaciones';

    protected $fillable = [
        'tipo', 'comprador_ml_id', 'comprador_nickname', 'publicacion_id_ml',
        'ml_publicacion_producto_id', 'ml_orden_id', 'pack_id_ml', 'estado', 'ultimo_mensaje_en',
    ];

    protected $casts = [
        'ultimo_mensaje_en' => 'datetime',
    ];

    public function publicacionProducto(): BelongsTo
    {
        return $this->belongsTo(MercadoLibrePublicacionProducto::class, 'ml_publicacion_producto_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(MercadoLibreOrden::class, 'ml_orden_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MercadoLibreMensaje::class, 'ml_conversacion_id')->orderBy('enviado_en');
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }
}
