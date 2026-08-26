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

    /**
     * Edita un pago ya registrado (espejo de `Cobranzas::actualizarCobro`).
     *
     * El movimiento de tesorería se actualiza junto con el pago y **sigue yendo en negativo**: es
     * plata que sale, y perder el signo acá descuadraría la cuenta y la Cta Cte del proveedor.
     */
    public function actualizarPago(Pago $pago, float $monto, CuentaTesoreria $cuenta, Carbon $fecha, ?string $nota = null): Pago
    {
        return DB::transaction(function () use ($pago, $monto, $cuenta, $fecha, $nota) {
            if ($pago->trashed()) {
                throw new \RuntimeException('El pago está anulado y no puede editarse.');
            }

            // Los pagos importados de Contagram SÍ tienen su movimiento en tesorería, pero quedaron
            // sin vincular (`origen_type` NULL). Antes esto bloqueaba la edición; ahora se aparea
            // el movimiento al vuelo con los valores VIEJOS del pago —que son los que el
            // movimiento todavía tiene— y se deja el vínculo puesto para siempre.
            $movimiento = $pago->movimientoTesoreria
                ?? $this->tesoreria->movimientoHuerfanoDe('pago', (int) $pago->cuenta_tesoreria_id, $pago->fecha, -(float) $pago->monto);

            if (! $movimiento) {
                throw new \RuntimeException('Este pago no tiene movimiento de tesorería y editarlo descuadraría la cuenta corriente del proveedor.');
            }

            if ($movimiento->origen_type === null) {
                $movimiento->forceFill([
                    'origen_type' => $pago->getMorphClass(),
                    'origen_id' => $pago->getKey(),
                ])->save();
            }

            $pago->update([
                'fecha' => $fecha,
                'cuenta_tesoreria_id' => $cuenta->id,
                'monto' => $monto,
                'nota' => $nota,
            ]);

            $movimiento->update([
                'monto' => -$monto,
                'cuenta_tesoreria_id' => $cuenta->id,
                'fecha' => $fecha,
            ]);

            return $pago;
        });
    }

    /**
     * Anula un pago: soft-delete del pago + de su movimiento de tesorería (0 saldo fantasma).
     *
     * El movimiento de un pago importado de Contagram existe pero quedó sin el vínculo
     * polimórfico, así que `movimientoTesoreria` es NULL y este método borraba el pago dejando
     * el egreso vivo en la cuenta: la deuda con el proveedor subía y la plata seguía descontada,
     * sin ningún error a la vista. El fallback por (tipo, cuenta, fecha, monto) cierra ese
     * agujero. Ver `Tesoreria::movimientoHuerfanoDe()`.
     */
    public function anularPago(Pago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $movimiento = $pago->movimientoTesoreria
                ?? $this->tesoreria->movimientoHuerfanoDe('pago', (int) $pago->cuenta_tesoreria_id, $pago->fecha, -(float) $pago->monto);

            $movimiento?->delete();
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

    /**
     * Deja el movimiento de tesorería del Gasto igual al Gasto, después de editarlo.
     *
     * Antes esto sólo cubría el alta diferida (quitar "pendiente" genera el movimiento recién ahí)
     * y salía sin hacer nada si el gasto **ya** tenía movimiento. Con eso, editar un gasto ya
     * conciliado no movía la caja: cambiarle la cuenta lo dejaba descontando de la anterior, y
     * cambiarle el monto o la fecha no se reflejaba en ningún saldo. Pasó con el gasto 9246
     * ("Ley 25413", $660,80 del 10/08/2026): se le cambió la cuenta a Banco Credicoop y el
     * movimiento se quedó en Caja del Local.
     */
    public function conciliarGasto(Gasto $gasto): void
    {
        $movimiento = $gasto->movimientoTesoreria;

        // Volvió a pendiente o se quedó sin cuenta: no puede seguir impactando ninguna caja.
        if ($gasto->pendiente || ! $gasto->cuenta_tesoreria_id) {
            $movimiento?->delete();

            return;
        }

        if ($movimiento === null) {
            $this->registrarGasto($gasto);

            return;
        }

        $movimiento->update([
            'cuenta_tesoreria_id' => $gasto->cuenta_tesoreria_id,
            'monto' => -(float) $gasto->monto,
            'fecha' => $gasto->fecha,
            'detalle' => $gasto->categoria?->nombre,
        ]);
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
