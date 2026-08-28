<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Constancia de un envío de información al contador por correo (spec 087, FR-024). */
class EnvioContador extends Model
{
    protected $table = 'envios_contador';

    protected $fillable = [
        'user_id', 'destinatarios', 'copia_remitente', 'anio', 'mes',
        'incluye_electronicas', 'incluye_manuales', 'incluye_pdfs',
        'archivos', 'asunto', 'estado', 'error', 'enviado_en',
    ];

    protected $casts = [
        'copia_remitente' => 'boolean',
        'incluye_electronicas' => 'boolean',
        'incluye_manuales' => 'boolean',
        'incluye_pdfs' => 'boolean',
        'archivos' => 'array',
        'enviado_en' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
