<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Entidad reutilizable entre remitos, alta rápida (spec 064, captura 04). */
class Transportista extends Model
{
    protected $table = 'transportistas';

    protected $fillable = ['nombre'];

    public function remitos(): HasMany
    {
        return $this->hasMany(Remito::class);
    }
}
