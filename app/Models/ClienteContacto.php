<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteContacto extends Model
{
    protected $table = 'cliente_contactos';

    protected $fillable = [
        'cliente_id',
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

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
