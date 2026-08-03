<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArcaLogAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'arca_logs_auditoria';

    protected $fillable = [
        'user_id', 'comprobante_fiscal_id', 'operacion', 'resultado',
        'mensaje', 'payload_solicitud', 'payload_respuesta',
    ];

    protected $casts = [
        'payload_solicitud' => 'array',
        'payload_respuesta' => 'array',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comprobanteFiscal(): BelongsTo
    {
        return $this->belongsTo(ComprobanteFiscal::class);
    }
}
