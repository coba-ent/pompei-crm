<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComprobanteFiscal extends Model
{
    use SoftDeletes;

    protected $table = 'comprobantes_fiscales';

    protected $fillable = [
        'comprobantable_type', 'comprobantable_id', 'punto_venta_id', 'tipo_comprobante',
        'numero', 'cae', 'cae_vencimiento', 'estado', 'motivo_rechazo',
        'comprobante_ajustado_id', 'respuesta_cruda',
    ];

    protected $casts = [
        'cae_vencimiento' => 'date',
        'respuesta_cruda' => 'array',
    ];

    public function comprobantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    public function comprobanteAjustado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'comprobante_ajustado_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(self::class, 'comprobante_ajustado_id');
    }

    public function logsAuditoria(): HasMany
    {
        return $this->hasMany(ArcaLogAuditoria::class);
    }

    public function aprobado(): bool
    {
        return $this->estado === 'aprobado' && ! empty($this->cae);
    }

    /** URL del QR fiscal AFIP (RG 4892), a codificar en el PDF cuando el comprobante está aprobado. */
    public function urlQrAfip(): ?string
    {
        if (! $this->aprobado()) {
            return null;
        }

        $comprobantable = $this->comprobantable;
        $certificado = \App\Models\CertificadoFiscal::activo();

        $payload = [
            'ver' => 1,
            'fecha' => optional($comprobantable?->fecha_emision)->format('Y-m-d'),
            'cuit' => (int) preg_replace('/\D/', '', $certificado?->cuit ?? '0'),
            'ptoVta' => $this->puntoVenta?->numero,
            'tipoCmp' => (new \App\Services\Arca\MapeadorComprobante())->cbteTipo($this->tipo_comprobante),
            'nroCmp' => (int) last(explode('-', $this->numero ?? '0-0')),
            'importe' => (float) ($comprobantable?->total ?? 0),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => 99,
            'nroDocRec' => 0,
            'tipoCodAut' => 'E',
            'codAut' => (int) $this->cae,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?p='.base64_encode(json_encode($payload));
    }
}
