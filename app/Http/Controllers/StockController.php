<?php

namespace App\Http\Controllers;

use App\Http\Requests\AjusteStockRequest;
use App\Http\Requests\TransferenciaStockRequest;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private StockService $stockService) {}

    /** Registrar un ajuste de stock (aumento/disminución) sobre un producto. */
    public function ajuste(AjusteStockRequest $request, Producto $producto): JsonResponse
    {
        $datos = $request->validated();

        $deposito = Deposito::findOrFail($datos['deposito_id']);
        $variante = ! empty($datos['variante_id'])
            ? ProductoVariante::findOrFail($datos['variante_id'])
            : null;

        $signo = $datos['operacion'] === 'disminucion' ? -1 : 1;
        $cantidad = $signo * (float) $datos['cantidad'];

        $stockActual = $this->stockService->ajustar(
            $producto,
            $variante,
            $deposito,
            $cantidad,
            $datos['descripcion'] ?? null,
            $request->user(),
            $datos['fecha'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Stock ajustado.',
            'stock_actual' => $stockActual,
        ]);
    }

    /** Movimiento de stock entre dos depósitos (transferencia). */
    public function transferencia(TransferenciaStockRequest $request, Producto $producto): JsonResponse
    {
        $datos = $request->validated();

        $salida = Deposito::findOrFail($datos['deposito_salida_id']);
        $entrada = Deposito::findOrFail($datos['deposito_entrada_id']);
        $variante = ! empty($datos['variante_id'])
            ? ProductoVariante::findOrFail($datos['variante_id'])
            : null;

        $resultado = $this->stockService->transferir(
            $producto,
            $variante,
            $salida,
            $entrada,
            (float) $datos['cantidad'],
            $datos['descripcion'] ?? null,
            $request->user(),
            $datos['fecha'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Transferencia registrada.',
            'stock_salida' => $resultado['salida'],
            'stock_entrada' => $resultado['entrada'],
        ]);
    }

    /** Histórico de movimientos de stock del producto (más recientes primero). */
    public function movimientos(Request $request, Producto $producto): JsonResponse
    {
        $movimientos = $producto->movimientos()
            ->with(['deposito', 'variante', 'usuario'])
            ->when($request->filled('deposito_id'), fn ($q) => $q->where('deposito_id', $request->input('deposito_id')))
            ->when($request->filled('variante_id'), fn ($q) => $q->where('variante_id', $request->input('variante_id')))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($m) => [
                'fecha' => optional($m->fecha)->format('Y-m-d'),
                'tipo' => $m->tipo,
                'deposito' => optional($m->deposito)->nombre,
                'variante' => optional($m->variante)->sku ?? optional($m->variante)->nombre,
                'cantidad' => (float) $m->cantidad,
                'descripcion' => $m->descripcion,
                'usuario' => optional($m->usuario)->name,
            ]);

        return response()->json(['data' => $movimientos]);
    }
}
