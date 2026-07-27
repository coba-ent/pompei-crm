<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CuentaTesoreria extends Model
{
    use HasFactory;

    protected $table = 'cuentas_tesoreria';

    protected $fillable = [
        'nombre', 'tipo', 'visible', 'es_sistema',
        'saldo_inicial', 'saldo_inicial_fecha', 'orden',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'es_sistema' => 'boolean',
        'saldo_inicial' => 'decimal:2',
        'saldo_inicial_fecha' => 'date',
    ];

    public function scopeVisibles(Builder $query): Builder
    {
        return $query->where('visible', true);
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function esCaja(): bool
    {
        return $this->tipo === 'efectivo';
    }

    public function esBanco(): bool
    {
        return $this->tipo === 'banco';
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoTesoreria::class, 'cuenta_tesoreria_id');
    }

    /**
     * ¿La cuenta tiene movimientos además de su Saldo Inicial? Bloquea la
     * eliminación física (FR-007), mismo patrón que Deposito::tieneOperaciones().
     */
    public function tieneOperaciones(): bool
    {
        return $this->movimientos()->where('tipo', '!=', 'saldo_inicial')->exists();
    }

    /** Saldo derivado: Σ(monto) hasta la fecha de corte (nunca una columna mutable — FR-014). */
    public function saldoA(?Carbon $fecha = null): float
    {
        return (float) $this->movimientos()
            ->when($fecha, fn (Builder $q) => $q->where('fecha', '<=', $fecha))
            ->sum('monto');
    }
}
