<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotaCreditoDebitoRequest;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** NC/ND (US4): wizard de 2 pasos sobre una venta, opcionalmente afecta stock. */
class NotaCreditoDebitoController extends Controller
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function store(StoreNotaCreditoDebitoRequest $request, Venta $venta): JsonResponse
    {
        $datos = $request->validated();
        $afectaStock = $datos['afecta_stock'] ?? false;

        $nota = DB::transaction(function () use ($datos, $venta, $afectaStock, $request) {
            $nota = $venta->notasCreditoDebito()->create([
                'tipo' => $datos['tipo'],
                'afecta_stock' => $afectaStock,
                'fecha_emision' => $datos['fecha_emision'],
                'monto' => $datos['monto'],
                'tipo_comprobante' => $venta->tipo_comprobante,
                'descripcion' => $datos['descripcion'] ?? null,
            ]);

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // NC repone stock (entrada); ND lo descuenta (salida) — research.md §9.
                $signo = $datos['tipo'] === 'credito' ? 1 : -1;

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create([
                        'producto_id' => $producto->id,
                        'cantidad' => $item['cantidad'],
                        'precio' => $item['precio'] ?? 0,
                        'origen' => 'venta_original',
                    ]);

                    $this->stockService->ajustar(
                        $producto,
                        null,
                        $deposito,
                        $signo * (float) $item['cantidad'],
                        ($datos['tipo'] === 'credito' ? 'Nota de Crédito' : 'Nota de Débito').' venta '.$venta->nro_comprobante,
                        $request->user(),
                    );
                }
            }

            return $nota;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Nota de '.($datos['tipo'] === 'credito' ? 'Crédito' : 'Débito').' creada correctamente.',
            'nota' => $nota,
            'a_cobrar' => $venta->aCobrar(),
        ], 201);
    }

    /** NC/ND sobre una Compra (US4, spec 009): recomputa la compra en vez de la venta. */
    public function storeCompra(StoreNotaCreditoDebitoRequest $request, Compra $compra): JsonResponse
    {
        $datos = $request->validated();
        $afectaStock = $datos['afecta_stock'] ?? false;

        $nota = DB::transaction(function () use ($datos, $compra, $afectaStock, $request) {
            $nota = $compra->notasCreditoDebito()->create([
                'tipo' => $datos['tipo'],
                'afecta_stock' => $afectaStock,
                'fecha_emision' => $datos['fecha_emision'],
                'monto' => $datos['monto'],
                'tipo_comprobante' => $compra->tipo_comprobante,
                'descripcion' => $datos['descripcion'] ?? null,
            ]);

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // NC de compra (proveedor te acredita, devolución) descuenta stock; ND lo suma — inverso a Venta.
                $signo = $datos['tipo'] === 'credito' ? -1 : 1;

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create([
                        'producto_id' => $producto->id,
                        'cantidad' => $item['cantidad'],
                        'precio' => $item['precio'] ?? 0,
                        'origen' => 'venta_original',
                    ]);

                    $this->stockService->ajustar(
                        $producto,
                        null,
                        $deposito,
                        $signo * (float) $item['cantidad'],
                        ($datos['tipo'] === 'credito' ? 'Nota de Crédito' : 'Nota de Débito').' compra '.$compra->nro_comprobante,
                        $request->user(),
                    );
                }
            }

            return $nota;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Nota de '.($datos['tipo'] === 'credito' ? 'Crédito' : 'Débito').' creada correctamente.',
            'nota' => $nota,
            'a_pagar' => $compra->aPagar(),
        ], 201);
    }
}
