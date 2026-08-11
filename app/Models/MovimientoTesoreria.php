<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimientoTesoreria extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'movimientos_tesoreria';

    protected $fillable = [
        'legacy_id',
        'cuenta_tesoreria_id', 'fecha', 'tipo', 'monto', 'detalle',
        'nro_comprobante', 'observacion', 'transferencia_id',
        'origen_type', 'origen_id', 'usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    /** Tipos que Tesorería crea y gestiona íntegramente (no originados en otro módulo). */
    private const TIPOS_NATIVOS = ['saldo_inicial', 'movimiento_entre_cuentas'];

    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class, 'cuenta_tesoreria_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function scopeHastaFecha(Builder $query, $fecha): Builder
    {
        return $query->where('fecha', '<=', $fecha);
    }

    public function scopeDelTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function getIngresoAttribute(): ?float
    {
        return $this->monto > 0 ? (float) $this->monto : null;
    }

    public function getEgresoAttribute(): ?float
    {
        return $this->monto < 0 ? (float) (-$this->monto) : null;
    }

    /** Sólo los movimientos nativos se borran físicamente desde la ficha (research §7-8). */
    public function esNativo(): bool
    {
        return in_array($this->tipo, self::TIPOS_NATIVOS, true);
    }
}
