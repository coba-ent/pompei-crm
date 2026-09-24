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

    /**
     * Registra un cobro de Venta y su movimiento de tesorería (SC-002).
     *
     * **`$monto` es el importe RECIBIDO del cliente** (spec 110). Si hubo vuelto, a la venta se le
     * imputa el **neto** (`recibido − vuelto`) y se registran DOS movimientos: el ingreso por lo
     * recibido y el egreso por el vuelto. Los dos comparten el vínculo polimórfico al cobro y se
     * distinguen por su `tipo` — por eso `Cobro::movimientoTesoreria()` filtra por `tipo='cobro'`.
     */
    public function registrarCobro(
        Venta $venta,
        float $monto,
        CuentaTesoreria $cuenta,
        Carbon $fecha,
        ?string $nota = null,
        float $vuelto = 0.0,
        ?CuentaTesoreria $cuentaVuelto = null,
    ): Cobro {
        return DB::transaction(function () use ($venta, $monto, $cuenta, $fecha, $nota, $vuelto, $cuentaVuelto) {
            $hayVuelto = $vuelto > 0 && $cuentaVuelto !== null;
            // Lo que salda la venta es el neto; el recibido vive en el movimiento de tesorería.
            $neto = $hayVuelto ? round($monto - $vuelto, 2) : $monto;

            $cobro = $venta->cobros()->create([
                'fecha' => $fecha,
                'cuenta_tesoreria_id' => $cuenta->id,
                'monto' => $neto,
                'nota' => $nota,
                'vuelto' => $hayVuelto ? $vuelto : null,
                'cuenta_vuelto_id' => $hayVuelto ? $cuentaVuelto->id : null,
            ]);

            // El ingreso entra por el importe REAL que recibió la caja, no por el neto.
            $this->tesoreria->registrarMovimiento(
                $cuenta, $monto, 'cobro', $cobro, $fecha,
                detalle: $venta->cliente?->nombre,
                nroComprobante: $venta->nro_comprobante,
            );

            if ($hayVuelto) {
                $this->registrarMovimientoVuelto($cobro, $venta, $vuelto, $cuentaVuelto, $fecha);
            }

            return $cobro;
        });
    }

    /**
     * Egreso por el vuelto entregado al cliente (spec 110).
     *
     * Monto **negativo**, como `pago` y `gasto`: el saldo de una cuenta se calcula sumando montos
     * con signo, así que un vuelto positivo inflaría la caja en lugar de reducirla.
     */
    private function registrarMovimientoVuelto(
        Cobro $cobro,
        Venta $venta,
        float $vuelto,
        CuentaTesoreria $cuentaVuelto,
        Carbon $fecha,
    ): void {
        $this->tesoreria->registrarMovimiento(
            $cuentaVuelto, -$vuelto, 'vuelto', $cobro, $fecha,
            detalle: $venta->cliente?->nombre,
            nroComprobante: $venta->nro_comprobante,
        );
    }

    /**
     * Edita un cobro ya cargado (monto/cuenta/fecha/nota), actualizando in-place su
     * MovimientoTesoreria asociado en vez de anular+recrear (research.md §1). No editable si el
     * cobro está anulado (soft-deleted) o si no tiene movimiento asociado (FR-006, FR-006a).
     */
    public function actualizarCobro(
        Cobro $cobro,
        float $monto,
        CuentaTesoreria $cuenta,
        Carbon $fecha,
        ?string $nota = null,
        float $vuelto = 0.0,
        ?CuentaTesoreria $cuentaVuelto = null,
    ): Cobro {
        return DB::transaction(function () use ($cobro, $monto, $cuenta, $fecha, $nota, $vuelto, $cuentaVuelto) {
            if ($cobro->trashed()) {
                throw new \RuntimeException('La cobranza está anulada y no puede editarse.');
            }

            // Idem `Pagos::actualizarPago()`: los cobros importados tienen movimiento pero sin el
            // vínculo, así que se aparea con los valores viejos y se deja vinculado.
            $movimiento = $cobro->movimientoTesoreria
                ?? $this->tesoreria->movimientoHuerfanoDe('cobro', (int) $cobro->cuenta_tesoreria_id, $cobro->fecha, (float) $cobro->monto);

            if (! $movimiento) {
                throw new \RuntimeException('Esta cobranza no tiene movimiento de tesorería y editarla descuadraría la cuenta corriente del cliente.');
            }

            if ($movimiento->origen_type === null) {
                $movimiento->forceFill([
                    'origen_type' => $cobro->getMorphClass(),
                    'origen_id' => $cobro->getKey(),
                ])->save();
            }

            $hayVuelto = $vuelto > 0 && $cuentaVuelto !== null;
            $neto = $hayVuelto ? round($monto - $vuelto, 2) : $monto;

            $cobro->update([
                'fecha' => $fecha,
                'cuenta_tesoreria_id' => $cuenta->id,
                'monto' => $neto,
                'nota' => $nota,
                'vuelto' => $hayVuelto ? $vuelto : null,
                'cuenta_vuelto_id' => $hayVuelto ? $cuentaVuelto->id : null,
            ]);

            // El ingreso se mueve por el importe recibido, no por el neto.
            $movimiento->update([
                'monto' => $monto,
                'cuenta_tesoreria_id' => $cuenta->id,
                'fecha' => $fecha,
            ]);

            $this->sincronizarMovimientoVuelto($cobro, $hayVuelto, $vuelto, $cuentaVuelto, $fecha);

            return $cobro->fresh();
        });
    }

    /**
     * Deja el movimiento de vuelto en línea con lo que quedó el cobro (spec 110, FR-012).
     *
     * Tres casos: ya tenía vuelto y sigue teniendo (se actualiza in-place, igual que el de
     * ingreso), no tenía y ahora sí (se crea), o tenía y ahora no (se soft-deletea). Sin el último
     * caso, quitar el vuelto de una cobranza dejaría el egreso vivo en la cuenta.
     */
    private function sincronizarMovimientoVuelto(
        Cobro $cobro,
        bool $hayVuelto,
        float $vuelto,
        ?CuentaTesoreria $cuentaVuelto,
        Carbon $fecha,
    ): void {
        $movimientoVuelto = $cobro->movimientoVuelto()->first();

        if (! $hayVuelto) {
            $movimientoVuelto?->delete();

            return;
        }

        if ($movimientoVuelto) {
            $movimientoVuelto->update([
                'monto' => -$vuelto,
                'cuenta_tesoreria_id' => $cuentaVuelto->id,
                'fecha' => $fecha,
            ]);

            return;
        }

        $this->registrarMovimientoVuelto($cobro, $cobro->venta, $vuelto, $cuentaVuelto, $fecha);
    }

    /**
     * Anula un cobro: soft-delete del cobro + de su movimiento de tesorería (0 saldo fantasma — SC-005).
     *
     * Mismo agujero que en `Pagos::anularPago()`, y acá era 100x más grande (25.259 cobros
     * importados sin vínculo): sin el fallback, anular un cobro histórico borraba el cobro y
     * dejaba el ingreso vivo en la cuenta.
     */
    public function anularCobro(Cobro $cobro): void
    {
        DB::transaction(function () use ($cobro) {
            // El apareo del huérfano usa el monto del cobro, que con vuelto es el NETO; el
            // movimiento importado histórico nunca tiene vuelto, así que sigue apareando bien.
            $movimiento = $cobro->movimientoTesoreria
                ?? $this->tesoreria->movimientoHuerfanoDe('cobro', (int) $cobro->cuenta_tesoreria_id, $cobro->fecha, (float) $cobro->monto);

            $movimiento?->delete();
            // Spec 110: el egreso del vuelto también se revierte. Sin esto queda vivo en la cuenta
            // y el saldo de la caja queda con un egreso sin contrapartida (FR-013).
            $cobro->movimientoVuelto()->first()?->delete();
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
            $otroIngreso->cuentaTesoreria, (float) $otroIngreso->monto, 'ingreso', $otroIngreso, $otroIngreso->fecha,
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
