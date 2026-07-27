<?php

namespace App\Services\Egresos;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\Pago;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de integración Egresos↔Tesorería (plan.md, research.md §4): todo
 * impacto de un Pago o Gasto en el saldo de una cuenta pasa por acá, dentro de
 * una transacción, y se revierte soft-deleteando el movimiento asociado
 * (research.md §5) — nunca con un asiento compensatorio. Análogo exacto a
 * `Services\Ingresos\Cobranzas` pero con signo de egreso.
 */
class Pagos
{
    public function __construct(private readonly Tesoreria $tesoreria)
    {
    }

    /** Registra un pago de Compra y su movimiento de tesorería, en negativo (SC-002). */
    public function registrarPago(Compra $compra, float $monto, CuentaTesoreria $cuenta, Carbon $fecha, ?string $nota = null): Pago
    {
        return DB::transaction(function () use ($compra, $monto, $cuenta, $fecha, $nota) {
            $pago = $compra->pagos()->create([
                'fecha' => $fecha,
                'cuenta_tesoreria_id' => $cuenta->id,
                'monto' => $monto,
                'nota' => $nota,
            ]);

            $this->tesoreria->registrarMovimiento(
                $cuenta, -$monto, 'pago', $pago, $fecha,
                detalle: $compra->proveedor?->nombre,
                nroComprobante: $compra->nro_comprobante,
            );

            return $pago;
        });
    }

    /** Anula un pago: soft-delete del pago + de su movimiento de tesorería (0 saldo fantasma). */
    public function anularPago(Pago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $pago->movimientoTesoreria?->delete();
            $pago->delete();
        });
    }

    /** Registra un Gasto no-pendiente: movimiento de tesorería en el alta, en negativo (FR-015). */
    public function registrarGasto(Gasto $gasto): void
    {
        if ($gasto->pendiente || ! $gasto->cuenta_tesoreria_id) {
            return;
        }

        $this->tesoreria->registrarMovimiento(
            $gasto->cuentaTesoreria, -(float) $gasto->monto, 'gasto', $gasto, $gasto->fecha,
            detalle: $gasto->categoria?->nombre,
        );
    }

    /** Conciliar: al editar quitando "pendiente" (con cuenta asignada) genera recién ahí el movimiento. */
    public function conciliarGasto(Gasto $gasto): void
    {
        if ($gasto->pendiente || $gasto->movimientoTesoreria || ! $gasto->cuenta_tesoreria_id) {
            return;
        }

        $this->registrarGasto($gasto);
    }

    /** Anula un Gasto: soft-delete + reversión del movimiento si lo tenía. */
    public function anularGasto(Gasto $gasto): void
    {
        DB::transaction(function () use ($gasto) {
            $gasto->movimientoTesoreria?->delete();
            $gasto->delete();
        });
    }
}
