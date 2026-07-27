<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo global de etiquetas, reutilizado entre Presupuestos y Ventas. */
class Etiqueta extends Model
{
    protected $table = 'etiquetas';

    protected $fillable = ['nombre'];
}
