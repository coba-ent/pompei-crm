<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración & Ajustes → Ventas (spec 043): fila única de defaults globales de "Crear Venta",
 * extendida con los defaults de "Crear Presupuesto" (reutiliza categoría/vendedor/lista de precios
 * de Ventas, sólo agrega días de validez) y "Crear Compra" (catálogo propio).
 */
class ConfiguracionVentas extends Model
{
    protected $table = 'configuracion_ventas';

    protected $fillable = [
        'categoria_id', 'vendedor_id', 'lista_precio_id', 'tipo_comprobante', 'dias_vto_cobro',
        'dias_validez_presupuesto', 'deposito_id',
        'categoria_compra_id', 'tipo_comprobante_compra', 'dias_vto_pago_compra', 'deposito_compra_id',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    public function categoriaCompra(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_compra_id');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function depositoCompra(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_compra_id');
    }
}
