<?php

namespace App\Models\Integraciones;

use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de cambio de tipo de publicación Clásica ↔ Premium (spec 115, FR-012).
 *
 * Se registra cada intento, salga bien o mal: el historial es lo que permite reconstruir por qué
 * una publicación quedó con el tipo o el precio que tiene.
 */
class MercadoLibreCambioTipoPublicacion extends Model
{
    protected $table = 'ml_cambios_tipo_publicacion';

    /** Tipo y precio publicados. */
    public const RESULTADO_CAMBIADO = 'cambiado';

    /** →Clásica con el tipo cambiado y el precio sin enviar: cobra de más, nunca de menos (FR-008b). */
    public const RESULTADO_CAMBIADO_PRECIO_PENDIENTE = 'cambiado_precio_pendiente';

    /** Mercado Libre ya tenía el tipo destino: sólo se actualizó el vínculo. */
    public const RESULTADO_SIN_CAMBIOS = 'sin_cambios';

    /** No se envió nada (sin precio destino, tipo no disponible, cortes, candado). */
    public const RESULTADO_BLOQUEADO = 'bloqueado';

    /** Mercado Libre rechazó el cambio de tipo antes de que se tocara el precio. */
    public const RESULTADO_FALLIDO = 'fallido';

    /** →Premium con el precio subido y el tipo rechazado: se restituyó el precio anterior (FR-008a). */
    public const RESULTADO_REVERTIDO = 'revertido';

    public const RESULTADOS = [
        self::RESULTADO_CAMBIADO,
        self::RESULTADO_CAMBIADO_PRECIO_PENDIENTE,
        self::RESULTADO_SIN_CAMBIOS,
        self::RESULTADO_BLOQUEADO,
        self::RESULTADO_FALLIDO,
        self::RESULTADO_REVERTIDO,
    ];

    protected $fillable = [
        'ml_publicacion_producto_id', 'ml_item_id', 'producto_id',
        'tipo_anterior', 'tipo_destino', 'precio_anterior', 'precio_nuevo', 'lista_precio_id',
        'resultado', 'mensaje', 'lote_id', 'usuario_id',
    ];

    protected $casts = [
        'precio_anterior' => 'decimal:2',
        'precio_nuevo' => 'decimal:2',
    ];

    public function vinculo(): BelongsTo
    {
        return $this->belongsTo(MercadoLibrePublicacionProducto::class, 'ml_publicacion_producto_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
