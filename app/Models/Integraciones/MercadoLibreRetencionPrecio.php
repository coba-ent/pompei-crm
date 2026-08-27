<?php

namespace App\Models\Integraciones;

use App\Models\ListaPrecio;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío de precio hacia Mercado Libre que el corte de seguridad frenó (spec 084).
 *
 * Guarda también las resueltas: el historial de por qué se frenó un precio y quién decidió qué es
 * un requisito (FR-015, FR-031). Una publicación tiene **a lo sumo una** retención `abierta`, y esa
 * regla la garantiza la base con un índice único sobre la columna generada `abierta_uk` — acá se
 * respeta, no se reimplementa.
 */
class MercadoLibreRetencionPrecio extends Model
{
    protected $table = 'retenciones_precio_ml';

    /** La caída supera el umbral configurado. El caso corriente. */
    public const MOTIVO_SUPERA_UMBRAL = 'supera_umbral';

    /** Precio propuesto menor o igual a cero. Se retiene sea cual sea el umbral. */
    public const MOTIVO_PRECIO_INVALIDO = 'precio_invalido';

    /** No se sabe qué precio está publicado, así que no hay contra qué comparar. */
    public const MOTIVO_SIN_REFERENCIA = 'sin_referencia';

    public const ESTADO_ABIERTA = 'abierta';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    /** La reemplazó una propuesta posterior. La escribe el sistema, sin usuario. */
    public const ESTADO_REEMPLAZADA = 'reemplazada';

    protected $fillable = [
        'ml_publicacion_producto_id', 'precio_propuesto', 'precio_publicado', 'caida_pct',
        'lista_precio_id', 'motivo', 'umbral_pct', 'estado',
        'resuelta_en', 'resuelta_por_id', 'precio_enviado',
    ];

    protected $casts = [
        'precio_propuesto' => 'decimal:2',
        'precio_publicado' => 'decimal:2',
        'caida_pct' => 'decimal:2',
        'umbral_pct' => 'decimal:2',
        'precio_enviado' => 'decimal:2',
        'resuelta_en' => 'datetime',
    ];

    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(MercadoLibrePublicacionProducto::class, 'ml_publicacion_producto_id');
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por_id');
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ABIERTA);
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::ESTADO_ABIERTA;
    }

    /**
     * Texto para la pantalla. Vive acá y no en la vista para que el listado, el modal y el
     * historial de operaciones digan todos lo mismo.
     */
    public function motivoLegible(): string
    {
        return match ($this->motivo) {
            self::MOTIVO_SUPERA_UMBRAL => sprintf(
                'La caída de %s%% supera el máximo permitido de %s%%.',
                number_format((float) $this->caida_pct, 2, ',', '.'),
                number_format((float) $this->umbral_pct, 2, ',', '.'),
            ),
            self::MOTIVO_PRECIO_INVALIDO => 'El precio propuesto es cero o negativo.',
            self::MOTIVO_SIN_REFERENCIA => 'No se sabe qué precio está publicado en Mercado Libre, '.
                'así que no se puede verificar cuánto baja.',
            default => 'Retenida.',
        };
    }
}
