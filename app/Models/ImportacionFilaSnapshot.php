<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de un producto inmediatamente antes de que una ImportacionCorrida lo
 * creara o modificara (spec 078). Una fila por producto creado/actualizado —
 * no se generan para filas fallidas ni productos no tocados.
 */
class ImportacionFilaSnapshot extends Model
{
    protected $table = 'importacion_filas_snapshot';

    protected $fillable = [
        'importacion_corrida_id',
        'producto_id',
        'modo',
        'existia',
        'estado_anterior',
        'precios_anteriores',
        'stock_anterior',
        'numero_fila',
        'limite_movimiento_stock_id',
        'limite_venta_item_id',
        'limite_compra_item_id',
        'estado_undo',
        'motivo_no_revertida',
    ];

    protected $casts = [
        'existia' => 'boolean',
        'estado_anterior' => 'array',
        'precios_anteriores' => 'array',
        'stock_anterior' => 'array',
    ];

    public function corrida(): BelongsTo
    {
        return $this->belongsTo(ImportacionCorrida::class, 'importacion_corrida_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
