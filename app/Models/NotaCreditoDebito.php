<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaCreditoDebito extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'notas_credito_debito';

    protected $fillable = [
        'legacy_id',
        'venta_id', 'compra_id', 'nota_ajustada_id', 'tipo', 'afecta_stock', 'mes_imputacion',
        'fecha_emision', 'monto', 'tipo_comprobante', 'nro_comprobante', 'descripcion',
        'nota_interna', 'impuestos',
        'descuento_general_tipo', 'descuento_general_pct', 'descuento_general_monto',
    ];

    protected $casts = [
        'afecta_stock' => 'boolean',
        'mes_imputacion' => 'date',
        'fecha_emision' => 'date',
        'monto' => 'decimal:2',
        'impuestos' => 'array',
        'descuento_general_pct' => 'decimal:2',
        'descuento_general_monto' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NotaCreditoDebitoItem::class);
    }

    public function comprobanteFiscal(): MorphOne
    {
        return $this->morphOne(ComprobanteFiscal::class, 'comprobantable');
    }

    /** "Documento que Ajusta" cuando apunta a otra NC/ND en vez del comprobante original (FR-013). */
    public function notaAjustada(): BelongsTo
    {
        return $this->belongsTo(self::class, 'nota_ajustada_id');
    }

    /** NC/ND que ajustan a ésta — no eliminable mientras alguna exista (FR-006). */
    public function notasQueLaAjustan(): HasMany
    {
        return $this->hasMany(self::class, 'nota_ajustada_id');
    }

    /** Bloquea edición/eliminación una vez que la nota tiene CAE aprobado por ARCA (FR-011). */
    public function tieneCaeAprobado(): bool
    {
        return $this->comprobanteFiscal?->aprobado() === true;
    }

    /**
     * "Documento que Ajusta" a mostrar en la tabla (research.md R4): prioridad
     * `notaAjustada` (encadenamiento, FR-013) sobre el comprobante fiscal de la
     * Venta/Compra original. Null si no hay nada que mostrar.
     */
    public function documentoQueAjusta(Venta|Compra|null $comprobanteOriginal): ?string
    {
        if ($this->nota_ajustada_id) {
            $notaAjustada = $this->notaAjustada;

            if (! $notaAjustada) {
                return null;
            }

            if ($notaAjustada->comprobanteFiscal?->aprobado()) {
                return $notaAjustada->comprobanteFiscal->numero;
            }

            if ($notaAjustada->tipo_comprobante && $notaAjustada->nro_comprobante) {
                return $notaAjustada->tipo_comprobante.' '.$notaAjustada->nro_comprobante;
            }

            return null;
        }

        if ($comprobanteOriginal?->comprobanteFiscal?->aprobado()) {
            return $comprobanteOriginal->comprobanteFiscal->numero;
        }

        // Compra nunca emite ComprobanteFiscal propio (el CAE es sólo para comprobantes propios
        // vía ARCA/Venta) — el "comprobante original" de una Compra es el nro_comprobante que
        // cargó el proveedor. Sin este fallback, "Documento que Ajusta" quedaba siempre en "-"
        // para toda NC/ND de Compra.
        if ($comprobanteOriginal?->tipo_comprobante && $comprobanteOriginal?->nro_comprobante) {
            return $comprobanteOriginal->tipo_comprobante.' '.$comprobanteOriginal->nro_comprobante;
        }

        return null;
    }
}
