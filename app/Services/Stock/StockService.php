<?php

namespace App\Services\Stock;

use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Encapsula el ajuste manual de stock: dentro de una transacción actualiza la
 * foto (`stocks`) y registra el histórico (`movimientos_stock`) de forma
 * atómica, dejando ambas tablas siempre consistentes (SC-003).
 */
class StockService
{
    /**
     * Aplica un ajuste de stock con signo (positivo = aumento, negativo =
     * disminución). Un ajuste puede dejar el stock negativo (research D7).
     *
     * @return float El stock resultante en el depósito para esa variante.
     */
    public function ajustar(
        Producto $producto,
        ?ProductoVariante $variante,
        Deposito $deposito,
        float $cantidadConSigno,
        ?string $descripcion = null,
        ?User $usuario = null,
        ?string $fecha = null,
    ): float {
        return DB::transaction(function () use ($producto, $variante, $deposito, $cantidadConSigno, $descripcion, $usuario, $fecha) {
            $stock = Stock::lockForUpdate()->firstOrNew([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $deposito->id,
            ]);

            $stock->cantidad = (float) $stock->cantidad + $cantidadConSigno;
            $stock->save();

            MovimientoStock::create([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $deposito->id,
                'tipo' => 'ajuste',
                'cantidad' => $cantidadConSigno,
                'descripcion' => $descripcion,
                'fecha' => $fecha ?: now()->toDateString(),
                'usuario_id' => $usuario?->id,
            ]);

            return (float) $stock->cantidad;
        });
    }

    /**
     * Movimiento de stock entre dos depósitos (transferencia): resta del depósito
     * de salida y suma al de entrada de forma atómica, registrando dos filas en
     * `movimientos_stock` con tipo `transferencia`. No altera el stock total del
     * producto, sólo su distribución.
     *
     * @return array{salida:float, entrada:float} Stock resultante en cada depósito.
     */
    public function transferir(
        Producto $producto,
        ?ProductoVariante $variante,
        Deposito $salida,
        Deposito $entrada,
        float $cantidad,
        ?string $descripcion = null,
        ?User $usuario = null,
        ?string $fecha = null,
    ): array {
        $cantidad = abs($cantidad);
        $fecha = $fecha ?: now()->toDateString();

        return DB::transaction(function () use ($producto, $variante, $salida, $entrada, $cantidad, $descripcion, $usuario, $fecha) {
            $stockSalida = Stock::lockForUpdate()->firstOrNew([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $salida->id,
            ]);
            $stockEntrada = Stock::lockForUpdate()->firstOrNew([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $entrada->id,
            ]);

            $stockSalida->cantidad = (float) $stockSalida->cantidad - $cantidad;
            $stockSalida->save();
            $stockEntrada->cantidad = (float) $stockEntrada->cantidad + $cantidad;
            $stockEntrada->save();

            $base = [
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'tipo' => 'transferencia',
                'descripcion' => $descripcion,
                'fecha' => $fecha,
                'usuario_id' => $usuario?->id,
            ];

            MovimientoStock::create($base + ['deposito_id' => $salida->id, 'cantidad' => -$cantidad]);
            MovimientoStock::create($base + ['deposito_id' => $entrada->id, 'cantidad' => $cantidad]);

            return [
                'salida' => (float) $stockSalida->cantidad,
                'entrada' => (float) $stockEntrada->cantidad,
            ];
        });
    }

    /**
     * Registra una salida de stock (venta) con `origen` polimórfico. La cantidad
     * se guarda con signo negativo en el movimiento. Reutiliza el lock atómico
     * de `mover()`.
     *
     * @return float El stock resultante en el depósito para esa variante.
     */
    public function registrarSalida(
        Producto $producto,
        ?ProductoVariante $variante,
        Deposito $deposito,
        float $cantidad,
        ?Model $origen = null,
        ?User $usuario = null,
    ): float {
        return $this->mover('salida', $producto, $variante, $deposito, abs($cantidad), $origen, $usuario);
    }

    /**
     * Registra una entrada de stock (reintegro por edición/baja de venta) con
     * `origen` polimórfico.
     *
     * @return float El stock resultante en el depósito para esa variante.
     */
    public function registrarEntrada(
        Producto $producto,
        ?ProductoVariante $variante,
        Deposito $deposito,
        float $cantidad,
        ?Model $origen = null,
        ?User $usuario = null,
    ): float {
        return $this->mover('entrada', $producto, $variante, $deposito, abs($cantidad), $origen, $usuario);
    }

    /** Stock disponible actual de una variante en un depósito. */
    public function disponibilidad(Producto $producto, ?ProductoVariante $variante, Deposito $deposito): float
    {
        $stock = Stock::where('producto_id', $producto->id)
            ->where('variante_id', $variante?->id)
            ->where('deposito_id', $deposito->id)
            ->first();

        return (float) ($stock->cantidad ?? 0);
    }

    /**
     * Núcleo atómico de un movimiento con origen. `entrada` suma; `salida` resta.
     * El movimiento guarda la cantidad con signo (research D1).
     */
    private function mover(
        string $tipo,
        Producto $producto,
        ?ProductoVariante $variante,
        Deposito $deposito,
        float $cantidad,
        ?Model $origen,
        ?User $usuario,
    ): float {
        $signo = $tipo === 'salida' ? -1 : 1;

        return DB::transaction(function () use ($tipo, $signo, $producto, $variante, $deposito, $cantidad, $origen, $usuario) {
            $stock = Stock::lockForUpdate()->firstOrNew([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $deposito->id,
            ]);

            $stock->cantidad = (float) $stock->cantidad + ($signo * $cantidad);
            $stock->save();

            MovimientoStock::create([
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'deposito_id' => $deposito->id,
                'tipo' => $tipo,
                'cantidad' => $signo * $cantidad,
                'origen_type' => $origen ? $origen->getMorphClass() : null,
                'origen_id' => $origen?->getKey(),
                'fecha' => now()->toDateString(),
                'usuario_id' => $usuario?->id,
            ]);

            return (float) $stock->cantidad;
        });
    }
}
