<?php

namespace App\Services\Ingresos;

use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Models\Venta;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de integración Ingresos↔Tesorería (plan.md, research.md §3): todo
 * impacto de un Cobro u Otro Ingreso en el saldo de una cuenta pasa por acá,
 * dentro de una transacción, y se revierte soft-deleteando el movimiento
 * asociado (research.md §4) — nunca con un asiento compensatorio.
 */
class Cobranzas
{
    public function __construct(private readonly Tesoreria $tesoreria)
    {
    }

    /** Registra un cobro de Venta y su movimiento de tesorería (SC-002). */
    public function registrarCobro(Venta $venta, float $monto, CuentaTesoreria $cuenta, Carbon $fecha, ?string $nota = null): Cobro
    {
        return DB::transaction(function () use ($venta, $monto, $cuenta, $fecha, $nota) {
            $cobro = $venta->cobros()->create([
                'fecha' => $fecha,
                'cuenta_tesoreria_id' => $cuenta->id,
                'monto' => $monto,
                'nota' => $nota,
            ]);

            $this->tesoreria->registrarMovimiento(
                $cuenta, $monto, 'cobro', $cobro, $fecha,
                detalle: $venta->cliente?->nombre,
                nroComprobante: $venta->nro_comprobante,
            );

            return $cobro;
        });
    }

    /** Anula un cobro: soft-delete del cobro + de su movimiento de tesorería (0 saldo fantasma — SC-005). */
    public function anularCobro(Cobro $cobro): void
    {
        DB::transaction(function () use ($cobro) {
            $cobro->movimientoTesoreria?->delete();
            $cobro->delete();
        });
    }

    /** Registra un Otro Ingreso no-pendiente: movimiento de tesorería en el alta (FR-021). */
    public function registrarOtroIngreso(OtroIngreso $otroIngreso): void
    {
        if ($otroIngreso->pendiente || ! $otroIngreso->cuenta_tesoreria_id) {
            return;
        }

        $this->tesoreria->registrarMovimiento(
            $otroIngreso->cuentaTesoreria, (float) $otroIngreso->monto, 'cobro', $otroIngreso, $otroIngreso->fecha,
            detalle: $otroIngreso->categoria?->nombre,
        );
    }

    /** Conciliar: al editar quitando "pendiente" (con cuenta asignada) genera recién ahí el movimiento. */
    public function conciliar(OtroIngreso $otroIngreso): void
    {
        if ($otroIngreso->pendiente || $otroIngreso->movimientoTesoreria || ! $otroIngreso->cuenta_tesoreria_id) {
            return;
        }

        $this->registrarOtroIngreso($otroIngreso);
    }

    /** Anula un Otro Ingreso: soft-delete + reversión del movimiento si lo tenía. */
    public function anularOtroIngreso(OtroIngreso $otroIngreso): void
    {
        DB::transaction(function () use ($otroIngreso) {
            $otroIngreso->movimientoTesoreria?->delete();
            $otroIngreso->delete();
        });
    }
}
