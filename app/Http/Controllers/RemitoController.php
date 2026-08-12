<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRemitoRequest;
use App\Http\Requests\UpdateRemitoRequest;
use App\Models\Compra;
use App\Models\DatosEmpresa;
use App\Models\Remito;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Remito (spec 064): CRUD puramente logístico sobre Venta o Compra, resolviendo el origen desde
 * la ruta para no duplicar la lógica entre VentaController y CompraController. No hay observers de
 * recálculo: el remito escribe únicamente en remitos + remito_items (FR-010).
 */
class RemitoController extends Controller
{
    /** Página completa de creación (Ventas) — precarga cliente, domicilio y líneas (FR-001, FR-002). */
    public function create(Venta $venta)
    {
        $venta->load('items.producto', 'cliente');

        return view('remitos.form', [
            'CurrentPage' => 'ventas',
            'venta' => $venta,
            'compra' => null,
            'remito' => null,
            'lineasPrecarga' => $this->lineasDesdeVenta($venta),
            'volverA' => route('ventas.show', $venta),
        ]);
    }

    /** Idem para Compras — domicilio precargado con el depósito que recibe, no el proveedor (FR-005). */
    public function createCompra(Compra $compra)
    {
        $compra->load('items.producto', 'proveedor', 'deposito');

        return view('remitos.form', [
            'CurrentPage' => 'compras',
            'venta' => null,
            'compra' => $compra,
            'remito' => null,
            'lineasPrecarga' => $this->lineasDesdeCompra($compra),
            'volverA' => route('compras.show', $compra),
        ]);
    }

    /** Edición (FR-016): mismo formulario, sin ningún campo bloqueado. */
    public function edit(Venta $venta, Remito $remito)
    {
        return $this->editarComun($remito, $venta, null);
    }

    public function editCompra(Compra $compra, Remito $remito)
    {
        return $this->editarComun($remito, null, $compra);
    }

    private function editarComun(Remito $remito, ?Venta $venta, ?Compra $compra)
    {
        $remito->load('items.producto', 'transportista');

        return view('remitos.form', [
            'CurrentPage' => $venta ? 'ventas' : 'compras',
            'venta' => $venta,
            'compra' => $compra,
            'remito' => $remito,
            'lineasPrecarga' => null,
            'volverA' => $venta ? route('ventas.show', $venta) : route('compras.show', $compra),
        ]);
    }

    public function store(StoreRemitoRequest $request, Venta $venta): JsonResponse
    {
        return $this->guardar($request, $venta, null);
    }

    public function storeCompra(StoreRemitoRequest $request, Compra $compra): JsonResponse
    {
        return $this->guardar($request, null, $compra);
    }

    private function guardar(StoreRemitoRequest $request, ?Venta $venta, ?Compra $compra): JsonResponse
    {
        $datos = $request->validated();

        $remito = DB::transaction(function () use ($datos, $venta, $compra) {
            $remito = Remito::create([
                'venta_id' => $venta?->id,
                'compra_id' => $compra?->id,
                'fecha' => $datos['fecha'],
                'nro_remito' => Remito::siguienteNumero(),
                'transportista_id' => $datos['transportista_id'] ?? null,
                'domicilio_entrega' => $datos['domicilio_entrega'] ?? null,
                'nota' => $datos['nota'] ?? null,
                'monto_asegurado' => $datos['monto_asegurado'] ?? null,
                'tipo' => $datos['tipo'] ?? 'X',
            ]);

            $this->guardarLineas($remito, $datos['items']);

            return $remito;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Remito '.$remito->nro_remito.' creado con éxito.',
            'remito' => $remito,
            'pdf' => route('remitos.pdf', $remito),
        ], 201);
    }

    public function update(UpdateRemitoRequest $request, Venta $venta, Remito $remito): JsonResponse
    {
        return $this->actualizar($request, $remito);
    }

    public function updateCompra(UpdateRemitoRequest $request, Compra $compra, Remito $remito): JsonResponse
    {
        return $this->actualizar($request, $remito);
    }

    private function actualizar(UpdateRemitoRequest $request, Remito $remito): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $remito) {
            $remito->update([
                'fecha' => $datos['fecha'],
                'transportista_id' => $datos['transportista_id'] ?? null,
                'domicilio_entrega' => $datos['domicilio_entrega'] ?? null,
                'nota' => $datos['nota'] ?? null,
                'monto_asegurado' => $datos['monto_asegurado'] ?? null,
                'tipo' => $datos['tipo'] ?? 'X',
            ]);

            $remito->items()->delete();
            $this->guardarLineas($remito, $datos['items']);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Remito actualizado correctamente.',
            'remito' => $remito->fresh(),
        ]);
    }

    public function destroy(Venta $venta, Remito $remito): JsonResponse
    {
        return $this->eliminar($remito);
    }

    public function destroyCompra(Compra $compra, Remito $remito): JsonResponse
    {
        return $this->eliminar($remito);
    }

    private function eliminar(Remito $remito): JsonResponse
    {
        // FR-017: borrado real (no soft delete) — el remito no es documento fiscal ni contable.
        $remito->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Remito eliminado correctamente.']);
    }

    /** Documento imprimible (FR-014): sin precios, sin IVA, sin totales, sin Monto Asegurado. */
    public function pdf(Remito $remito)
    {
        $remito->load([
            'items.producto', 'transportista',
            'venta.cliente.condicionIva', 'venta.cliente.contactos',
            'compra.proveedor.condicionIva', 'compra.proveedor.contactos',
        ]);
        $datosEmpresa = DatosEmpresa::instancia();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('remitos.pdf', compact('remito', 'datosEmpresa'));

        return $pdf->stream('remito-'.($remito->nro_remito ?? $remito->id).'.pdf', ['Content-Disposition' => 'inline']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineasDesdeVenta(Venta $venta): array
    {
        return $venta->items->map(fn ($item) => [
            'producto_id' => $item->producto_id,
            'codigo' => $item->producto?->codigo,
            'descripcion' => $item->descripcion,
            'observacion' => null,
            'cantidad' => (float) $item->cantidad,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineasDesdeCompra(Compra $compra): array
    {
        return $compra->items->map(fn ($item) => [
            'producto_id' => $item->producto_id,
            'codigo' => $item->producto?->codigo,
            'descripcion' => $item->descripcion,
            'observacion' => null,
            'cantidad' => (float) $item->cantidad,
        ])->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function guardarLineas(Remito $remito, array $items): void
    {
        foreach ($items as $item) {
            $producto = ! empty($item['producto_id']) ? \App\Models\Producto::find($item['producto_id']) : null;

            $remito->items()->create([
                'producto_id' => $producto?->id,
                'codigo' => $item['codigo'] ?? $producto?->codigo,
                'descripcion' => $item['descripcion'] ?? $producto?->nombre,
                'observacion' => $item['observacion'] ?? null,
                'cantidad' => $item['cantidad'],
            ]);
        }
    }

}
