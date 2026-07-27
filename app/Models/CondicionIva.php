<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CondicionIva extends Model
{
    protected $table = 'condiciones_iva';

    protected $fillable = ['nombre', 'codigo_afip', 'requiere_cuit'];

    protected $casts = [
        'requiere_cuit' => 'boolean',
    ];

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }
}
