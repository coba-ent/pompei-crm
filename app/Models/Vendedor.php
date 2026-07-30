<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo propio de Vendedores (spec 020): tabla plana, sólo `nombre` único, sin ABM en
 * pantalla propia (FR-012) — el alta/edición/baja vive en el select inline de Venta,
 * Presupuesto y las configuraciones de Tiendanube/MercadoLibre (VendedorController).
 */
class Vendedor extends Model
{
    protected $table = 'vendedores';

    protected $fillable = ['nombre'];
}
