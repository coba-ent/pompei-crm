<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Configuración & Ajustes → Ventas (spec 043): fila única de defaults globales de "Crear Venta". */
class ConfiguracionVentas extends Model
{
    protected $table = 'configuracion_ventas';

    protected $fillable = [
        'categoria_id', 'vendedor_id', 'lista_precio_id', 'tipo_comprobante', 'dias_vto_cobro',
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
}
