<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retención (Ganancias/IVA/Seguridad Social/Sellos/Ingresos Brutos) sufrida en un Cobro o Pago. */
class Retencion extends Model
{
    use HasFactory;

    protected $table = 'retenciones';

    protected $fillable = ['cobro_id', 'pago_id', 'fecha', 'monto', 'tipo_retencion', 'nro_comprobante', 'descripcion'];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }
}
