<?php

namespace App\Services\Ingresos;

use App\Models\AplicacionCredito;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saldo a favor aplicable a otros comprobantes (spec 072).
 *
 * **Este servicio no toca Tesorería y no puede llegar a tocarla**: no llama a `Cobranzas`, a
 * `Pagos` ni a `Tesoreria`, así que no hay camino de código por el que aplicar un crédito genere un
 * `MovimientoTesoreria` (plan.md §Riesgo principal, barrera 1). Aplicar crédito es una
 * **transferencia de saldo** entre dos comprobantes del mismo cliente/proveedor: lo que baja de un
 * lado sube del otro y el saldo de cuenta corriente queda idéntico (FR-003a).
 *
 * Es agnóstico del tipo de comprobante: sirve igual para Venta (crédito de cliente) que para
 * Compra (crédito de proveedor), que son universos separados y nunca se compensan entre sí.
 */
class CreditoCliente
{
    private const TOLERANCIA = 0.005;

    /**
     * Crédito disponible de UN comprobante: el saldo a favor que le dejó su Nota de Crédito, menos
     * lo que ya cedió a otros comprobantes.
     *
     * Se mide por el **saldo a favor efectivo**, nunca por el monto nominal de la nota (clarificación
     * del 21/08/2026): una NC sobre un comprobante impago sólo cancela deuda y no genera crédito;
     * tomar el monto crearía crédito de la nada.
     */
    public function disponible(Model $comprobante): float
    {
        if (! $this->tieneNotaCreditoVigente($comprobante)) {
            return 0.0;
        }

        $saldoBase = $this->saldoBase($comprobante);
        $disponible = max(0.0, -$saldoBase) - $this->cedido($comprobante);

        return round(max(0.0, $disponible), 2);
    }

    /**
     * Orígenes con crédito disponible para imputar a `$destino`, del más antiguo al más nuevo
     * (FR-008). Excluye al propio destino (FR-009a) y a los comprobantes de otro cliente/proveedor.
     *
     * @return Collection<int, array{comprobante: Model, disponible: float, nota: ?NotaCreditoDebito}>
     */
    public function disponiblePara(Model $destino): Collection
    {
        return $this->candidatos($destino)
            ->map(fn (Model $origen) => [
                'comprobante' => $origen,
                'disponible' => $this->disponible($origen),
                'nota' => $this->notaDeOrigen($origen),
            ])
            ->filter(fn (array $fila) => $fila['disponible'] > self::TOLERANCIA)
            ->values();
    }

    /** Suma del crédito de todos los comprobantes del cliente/proveedor aplicable a `$destino` (FR-003). */
    public function disponibleTotalPara(Model $destino): float
    {
        return round((float) $this->disponiblePara($destino)->sum('disponible'), 2);
    }

    /**
     * Imputa `$monto` de saldo a favor al comprobante `$destino`, consumiendo del origen más antiguo
     * al más nuevo salvo que se indique `$origenId`.
     *
     * Todo ocurre dentro de una transacción con `lockForUpdate()` sobre los orígenes: dos operadores
     * aplicando el mismo crédito a la vez no pueden consumirlo dos veces (FR-013). El disponible se
     * recalcula **dentro** del lock, así que la segunda operación ve el consumo de la primera.
     *
     * @return Collection<int, AplicacionCredito>
     *
     * @throws \RuntimeException con el mensaje que ve el usuario (contrato §2)
     */
    public function aplicar(Model $destino, float $monto, Carbon $fecha, ?string $nota = null, ?int $origenId = null, ?int $usuarioId = null): Collection
    {
        if ($monto <= self::TOLERANCIA) {
            throw new \RuntimeException('El monto debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($destino, $monto, $fecha, $nota, $origenId, $usuarioId) {
            $this->bloquearCandidatos($destino);

            $destino->refresh();

            $pendiente = $this->saldoPendiente($destino);

            if ($pendiente <= self::TOLERANCIA) {
                throw new \RuntimeException($this->esVenta($destino)
                    ? 'El comprobante no tiene saldo a cobrar.'
                    : 'El comprobante no tiene saldo a pagar.');
            }

            if ($monto > $pendiente + self::TOLERANCIA) {
                throw new \RuntimeException($this->esVenta($destino)
                    ? 'El monto supera el saldo a cobrar.'
                    : 'El monto supera el saldo a pagar.');
            }

            $origenes = $this->disponiblePara($destino);

            if ($origenId !== null) {
                $origenes = $origenes->filter(fn (array $f) => (int) $f['comprobante']->id === $origenId)->values();

                if ($origenes->isEmpty()) {
                    throw new \RuntimeException($this->mensajeOrigenInvalido($destino, $origenId));
                }
            }

            $total = round((float) $origenes->sum('disponible'), 2);

            if ($total <= self::TOLERANCIA) {
                throw new \RuntimeException($this->esVenta($destino)
                    ? 'El cliente no tiene saldo a favor para aplicar.'
                    : 'El proveedor no tiene saldo a favor para aplicar.');
            }

            if ($monto > $total + self::TOLERANCIA) {
                throw new \RuntimeException($this->esVenta($destino)
                    ? 'El monto supera el saldo a favor disponible del cliente.'
                    : 'El monto supera el saldo a favor disponible del proveedor.');
            }

            $restante = $monto;
            $creadas = collect();

            foreach ($origenes as $fila) {
                if ($restante <= self::TOLERANCIA) {
                    break;
                }

                $aplicado = round(min($restante, $fila['disponible']), 2);

                $creadas->push(AplicacionCredito::create([
                    'origen_type' => $fila['comprobante']->getMorphClass(),
                    'origen_id' => $fila['comprobante']->id,
                    'destino_type' => $destino->getMorphClass(),
                    'destino_id' => $destino->id,
                    'nota_credito_debito_id' => $fila['nota']?->id,
                    'monto' => $aplicado,
                    'fecha' => $fecha,
                    'nota' => $nota,
                    'usuario_id' => $usuarioId,
                ]));

                $restante = round($restante - $aplicado, 2);
            }

            return $creadas;
        });
    }

    /** Anula una aplicación: el crédito vuelve a estar disponible en el origen (FR-011). */
    public function anular(AplicacionCredito $aplicacion): void
    {
        $aplicacion->delete();
    }

    // -----------------------------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------------------------

    private function esVenta(Model $comprobante): bool
    {
        return $comprobante instanceof Venta;
    }

    /**
     * Toma el lock de escritura sobre los comprobantes que podrían ceder crédito. Se hace antes de
     * leer sus saldos para que dos aplicaciones simultáneas se serialicen (FR-013).
     */
    private function bloquearCandidatos(Model $destino): void
    {
        $ids = $this->candidatos($destino)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $modelo = $this->esVenta($destino) ? Venta::class : Compra::class;
        $modelo::query()->whereIn('id', $ids)->lockForUpdate()->get(['id']);
    }

    /** Saldo sin los términos de crédito: total + ND − NC − cobrado/pagado. */
    private function saldoBase(Model $comprobante): float
    {
        $movido = $this->esVenta($comprobante) ? $comprobante->cobrado() : $comprobante->pagado();

        return round(
            (float) $comprobante->total
            + $comprobante->totalNotasDebito()
            - $comprobante->totalNotasCredito()
            - $movido,
            2
        );
    }

    /** Saldo que todavía queda por cobrar/pagar, ya con los créditos aplicados. */
    private function saldoPendiente(Model $comprobante): float
    {
        return $this->esVenta($comprobante) ? $comprobante->aCobrar() : $comprobante->aPagar();
    }

    private function cedido(Model $comprobante): float
    {
        return round((float) AplicacionCredito::query()
            ->where('origen_type', $comprobante->getMorphClass())
            ->where('origen_id', $comprobante->id)
            ->sum('monto'), 2);
    }

    private function tieneNotaCreditoVigente(Model $comprobante): bool
    {
        return $comprobante->notasCreditoDebito()->where('tipo', 'credito')->exists();
    }

    /**
     * NC del origen a la que se atribuye el crédito: la **más antigua** vigente. Es sólo trazabilidad
     * (FR-015/FR-016) — el importe disponible no sale de la nota sino del saldo a favor efectivo.
     */
    private function notaDeOrigen(Model $comprobante): ?NotaCreditoDebito
    {
        return $comprobante->notasCreditoDebito()
            ->where('tipo', 'credito')
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->first();
    }

    /**
     * Comprobantes del mismo cliente/proveedor que podrían tener crédito, ordenados del más antiguo
     * al más nuevo. Filtra en SQL por "tiene alguna NC" para no recorrer las 23.800 ventas.
     *
     * @return Collection<int, Model>
     */
    private function candidatos(Model $destino): Collection
    {
        $esVenta = $this->esVenta($destino);
        $modelo = $esVenta ? Venta::class : Compra::class;
        $campoEntidad = $esVenta ? 'cliente_id' : 'proveedor_id';

        if ($destino->{$campoEntidad} === null) {
            return collect();
        }

        return $modelo::query()
            ->where($campoEntidad, $destino->{$campoEntidad})
            ->where('id', '!=', $destino->id)
            ->whereHas('notasCreditoDebito', fn ($q) => $q->where('tipo', 'credito'))
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();
    }

    private function mensajeOrigenInvalido(Model $destino, int $origenId): string
    {
        if ($origenId === (int) $destino->id) {
            return 'No se puede aplicar el saldo a favor de un comprobante sobre sí mismo.';
        }

        $esVenta = $this->esVenta($destino);
        $modelo = $esVenta ? Venta::class : Compra::class;
        $campoEntidad = $esVenta ? 'cliente_id' : 'proveedor_id';
        $origen = $modelo::find($origenId);

        if ($origen && $origen->{$campoEntidad} !== $destino->{$campoEntidad}) {
            return $esVenta
                ? 'El comprobante de origen es de otro cliente.'
                : 'El comprobante de origen es de otro proveedor.';
        }

        return 'El comprobante de origen no tiene saldo a favor disponible.';
    }
}
