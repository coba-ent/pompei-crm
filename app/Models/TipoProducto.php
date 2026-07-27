<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo "Tipo de Producto" de Contagram (Compra y Venta, Consignación,
 * Fabricado, Insumo, …). Distinto del campo `tipo` (producto/servicio).
 */
class TipoProducto extends Model
{
    protected $table = 'tipos_producto';

    protected $fillable = ['nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'tipo_producto_id');
    }
}
