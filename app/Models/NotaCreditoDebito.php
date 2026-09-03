<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaCreditoDebito extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'notas_credito_debito';

    protected $fillable = [
        'legacy_id',
        'venta_id', 'compra_id', 'nota_ajustada_id', 'tipo', 'afecta_stock', 'mes_imputacion',
        'fecha_emision', 'monto', 'tipo_comprobante', 'nro_comprobante', 'sin_comprobante_fiscal', 'descripcion',
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

    /**
     * Importe que representa el Descuento General de la nota sobre el subtotal de sus ítems
     * (sin IVA), para la fila "Descuento General" del PDF (spec 098, FR-009).
     *
     * A diferencia de Presupuesto/Venta/Compra, `notas_credito_debito` NO persiste un subtotal
     * intermedio de ítems — el `monto` final se calcula 100% client-side en
     * `notas-credito-debito.js::recalcular()` y se guarda tal cual llega, sin que el backend lo
     * recalcule. Por eso este método replica ESE MISMO algoritmo (no uno propio): si usara una
     * fórmula distinta, la fila nueva del PDF podría no cuadrar con el `monto` ya impreso más
     * abajo, exactamente la clase de inconsistencia que esta spec busca eliminar.
     */
    public function montoDescuentoGeneral(): float
    {
        $subtotalSinDescuento = $this->items->sum(function (NotaCreditoDebitoItem $item) {
            $bruto = (float) $item->cantidad * (float) $item->precio;

            return $bruto - ($bruto * (float) ($item->descuento_pct ?? 0) / 100);
        });

        if ($this->descuento_general_tipo === 'monto') {
            $factor = $subtotalSinDescuento > 0
                ? max(0, 1 - ((float) ($this->descuento_general_monto ?? 0) / $subtotalSinDescuento))
                : 1;
        } else {
            $factor = 1 - ((float) ($this->descuento_general_pct ?? 0) / 100);
        }

        return round($subtotalSinDescuento * (1 - $factor), 2);
    }

    /** Comprobante fiscal vigente: el aprobado si existe, si no el último intento (ver Venta::comprobanteFiscal()). */
    public function comprobanteFiscal(): MorphOne
    {
        return $this->morphOne(ComprobanteFiscal::class, 'comprobantable')
            ->orderByRaw("CASE WHEN estado = 'aprobado' THEN 0 ELSE 1 END")
            ->orderByDesc('id');
    }

    /** Historial completo de intentos contra ARCA, incluidos los rechazados. */
    public function comprobantesFiscales(): MorphMany
    {
        return $this->morphMany(ComprobanteFiscal::class, 'comprobantable');
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

    /** Aplicaciones de saldo a favor que se justificaron con esta nota (spec 072). */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionCredito::class);
    }

    /**
     * Bloquea la eliminación mientras haya saldo a favor de esta nota imputado a otro comprobante
     * (spec 072, FR-012): nunca puede quedar un comprobante saldado por un crédito cuyo origen ya
     * no existe.
     */
    public function tieneCreditoAplicado(): bool
    {
        return $this->aplicaciones()->exists();
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
