<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorContacto extends Model
{
    protected $table = 'proveedor_contactos';

    protected $fillable = [
        'proveedor_id',
        'nombre',
        'apellido',
        'telefono',
        'telefono_celular',
        'email',
        'enviar_mails',
    ];

    protected $casts = [
        'enviar_mails' => 'boolean',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }
}
