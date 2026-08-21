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
        $mapeador = new \App\Services\Arca\MapeadorComprobante();

        // El QR tiene que reproducir exactamente lo que se le mandó a ARCA: si el receptor, el tipo
        // de comprobante o el importe no coinciden con el CAE, la constatación falla.
        $cliente = $comprobantable?->cliente ?? $comprobantable?->venta?->cliente;
        [$docTipo, $docNro] = $mapeador->documentoReceptor($cliente?->datosFiscalesArca() ?? []);

        $tipoNota = $comprobantable instanceof \App\Models\NotaCreditoDebito ? $comprobantable->tipo : null;
        $importe = (float) ($comprobantable->total ?? $comprobantable->monto ?? 0);

        $payload = [
            'ver' => 1,
            'fecha' => optional($comprobantable?->fecha_emision)->format('Y-m-d'),
            'cuit' => (int) preg_replace('/\D/', '', $certificado?->cuit ?? '0'),
            'ptoVta' => (int) $this->puntoVenta?->numero,
            'tipoCmp' => $mapeador->cbteTipo($this->tipo_comprobante, $tipoNota),
            'nroCmp' => (int) last(explode('-', $this->numero ?? '0-0')),
            'importe' => round($importe, 2),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => $docTipo,
            'nroDocRec' => (int) $docNro,
            'tipoCodAut' => 'E',
            'codAut' => (int) $this->cae,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?p='.base64_encode(json_encode($payload));
    }
}
