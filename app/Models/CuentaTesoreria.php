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

    /**
     * Orden de presentación de las cuentas: por el campo `orden` y después por nombre.
     * Las cuentas sin `orden` cargado (NULL) van al final — en MySQL un NULL ordena
     * primero, y eso hacía que una cuenta con orden explícito (ej. "Caja del Local")
     * apareciera última, detrás de todas las que nunca se ordenaron.
     */
    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderByRaw('orden IS NULL')->orderBy('orden')->orderBy('nombre');
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Cuentas donde puede **entrar** plata: cobranzas y otros ingresos.
     *
     * Excluye las de tipo `a_pagar` — tarjeta corporativa, cheques propios, resúmenes a pagar —
     * que son pasivos y no reciben cobros. No es una restricción teórica: en los seis años de
     * histórico de Contagram no hay **ni un solo cobro** en una cuenta `a_pagar`, sobre 23.173.
     */
    public function scopeParaCobrar(Builder $query): Builder
    {
        return $query->whereIn('tipo', ['efectivo', 'banco', 'a_cobrar']);
    }

    /**
     * NO existe un `paraPagar()` simétrico, y es a propósito.
     *
     * La regla espejo —excluir `a_cobrar` de pagos y gastos— parecía obvia pero **los datos la
     * desmienten**: hay cuentas `a_cobrar` con egresos reales (`Cheque de Terceros` tiene 10 pagos
     * y 2 gastos, `PAYWAY QR` un pago, `Mastercard` un gasto). Un cheque de tercero se recibe para
     * cobrar y se endosa para pagar: las dos direcciones son legítimas sobre la misma cuenta.
     *
     * El caso que originalmente motivó esta nota —`Banco Credicoop` tipificada `a_cobrar` con 575
     * pagos y 2.187 gastos— ya no aplica: el 11/08/2026 se corrigió a `banco`, junto con las cinco
     * tarjetas que estaban como `banco` y `Juan USD Personal` que pasó a `efectivo`.
     */

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
