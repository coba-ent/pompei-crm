<?php

namespace App\Services\Import;

use App\Models\CompraItem;
use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ImportacionFilaSnapshot;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use App\Models\VentaItem;
use App\Services\Stock\StockService;
use App\Support\OrigenCambioPrecio;
use Illuminate\Support\Facades\DB;

/**
 * Revierte una `ImportacionCorrida` de Productos & Servicios a partir de sus
 * `ImportacionFilaSnapshot` (spec 078). El undo es parcial: una fila cuyo
 * producto tuvo actividad de negocio posterior al import (venta, compra,
 * ajuste/transferencia de stock, u otra corrida de import más reciente) no se
 * revierte y se reporta con motivo, sin abortar el resto (FR-009).
 */
class DeshacerImportacionService
{
    public function __construct(private StockService $stockService) {}

    /**
     * @return array{revertidas: int, no_revertidas: array<int, array{producto_id: ?int, numero_fila: int, motivo: string}>}
     */
    public function deshacer(ImportacionCorrida $corrida, ?User $usuario): array
    {
        if (! $corrida->puedeDeshacer()) {
            throw new \DomainException('Esta corrida ya no se puede deshacer.');
        }

        $revertidas = 0;
        $noRevertidas = [];

        $filas = $corrida->filas()->where('estado_undo', 'pendiente')->get();

        foreach ($filas as $fila) {
            try {
                $motivo = $this->deshacerFila($corrida, $fila, $usuario);
            } catch (\Throwable $e) {
                $motivo = 'Error inesperado al deshacer esta fila: '.$e->getMessage();
            }

            if ($motivo === null) {
                $fila->update(['estado_undo' => 'revertida']);
                $revertidas++;
            } else {
                $fila->update(['estado_undo' => 'no_revertida', 'motivo_no_revertida' => $motivo]);
                $noRevertidas[] = ['producto_id' => $fila->producto_id, 'numero_fila' => $fila->numero_fila, 'motivo' => $motivo];
            }
        }

        $corrida->update([
            'deshecho_en' => now(),
            'deshecho_por_id' => $usuario?->id,
            'filas_revertidas' => $revertidas,
            'filas_no_revertidas' => count($noRevertidas),
        ]);

        return ['revertidas' => $revertidas, 'no_revertidas' => $noRevertidas];
    }

    /** @return string|null null = revertida OK; string = motivo por el que no se pudo revertir */
    private function deshacerFila(ImportacionCorrida $corrida, ImportacionFilaSnapshot $fila, ?User $usuario): ?string
    {
        $producto = Producto::find($fila->producto_id);

        if (! $producto) {
            return null; // ya no existe (borrado manual posterior) — no es un fallo del undo (Edge Cases spec.md)
        }

        // FR-016: si otra corrida vigente más reciente tocó este mismo producto después,
        // no revertir acá para no pisar el resultado de esa corrida.
        $corridaMasReciente = ImportacionFilaSnapshot::where('producto_id', $producto->id)
            ->where('importacion_corrida_id', '!=', $corrida->id)
            ->whereHas('corrida', fn ($q) => $q->where('entidad', 'productos')->whereNull('deshecho_en')->where('confirmado_en', '>', $corrida->confirmado_en))
            ->exists();

        if ($corridaMasReciente) {
            return 'El producto fue modificado por una corrida de import más reciente todavía vigente.';
        }

        return $fila->modo === 'alta'
            ? $this->deshacerAlta($producto, $fila)
            : $this->deshacerActualizacion($producto, $fila, $usuario);
    }

    private function deshacerAlta(Producto $producto, ImportacionFilaSnapshot $fila): ?string
    {
        if ($this->tieneActividadPosterior($producto, $fila)) {
            return 'El producto tiene ventas, compras o movimientos de stock posteriores al import.';
        }

        $producto->update(['activo' => false]);

        return null;
    }

    private function deshacerActualizacion(Producto $producto, ImportacionFilaSnapshot $fila, ?User $usuario): ?string
    {
        if ($this->tieneActividadPosterior($producto, $fila)) {
            return 'El producto tiene ventas, compras o movimientos de stock posteriores al import.';
        }

        $estadoAnterior = $fila->estado_anterior;
        unset($estadoAnterior['id'], $estadoAnterior['created_at'], $estadoAnterior['updated_at']);
        $producto->update($estadoAnterior);

        OrigenCambioPrecio::durante(OrigenCambioPrecio::DESHACER_IMPORT, function () use ($producto, $fila) {
            foreach (($fila->precios_anteriores ?? []) as $precioAnterior) {
                $producto->precios()->updateOrCreate(
                    ['lista_precio_id' => $precioAnterior['lista_precio_id']],
                    ['precio' => $precioAnterior['precio']],
                );
            }
        });

        if ($producto->controlaStock()) {
            foreach (($fila->stock_anterior ?? []) as $stockAnterior) {
                $this->stockService->fijar(
                    $producto,
                    null,
                    Deposito::findOrFail($stockAnterior['deposito_id']),
                    (float) $stockAnterior['cantidad'],
                    'Ajuste (deshacer import)',
                    $usuario,
                );
            }
        }

        return null;
    }

    /** research.md R4/R5, adaptado en implementación: compara contra los límites capturados en el snapshot. */
    private function tieneActividadPosterior(Producto $producto, ImportacionFilaSnapshot $fila): bool
    {
        if (VentaItem::where('producto_id', $producto->id)->where('id', '>', $fila->limite_venta_item_id ?? 0)->exists()) {
            return true;
        }

        if (CompraItem::where('producto_id', $producto->id)->where('id', '>', $fila->limite_compra_item_id ?? 0)->exists()) {
            return true;
        }

        return MovimientoStock::where('producto_id', $producto->id)->where('id', '>', $fila->limite_movimiento_stock_id ?? 0)->exists();
    }
}
