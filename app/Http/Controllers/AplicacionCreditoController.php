<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAplicacionCreditoRequest;
use App\Models\AplicacionCredito;
use App\Models\Compra;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Aplicación de saldo a favor a un comprobante (spec 072,
 * `specs/072-saldo-favor-credito-nc/contracts/aplicaciones-credito-api.md`).
 *
 * **No pasa por `Cobranzas` ni por `Pagos`**: aplicar crédito no es plata que entra ni que sale, y
 * por eso no genera movimientos de tesorería (FR-017). El permiso es el mismo que ya exige cargar
 * una cobranza o un pago — se resuelve por el grupo de rutas, sin permiso nuevo (FR-022).
 */
class AplicacionCreditoController extends Controller
{
    public function __construct(private readonly CreditoCliente $credito)
    {
    }

    // -----------------------------------------------------------------------------------
    // Ventas
    // -----------------------------------------------------------------------------------

    public function disponibleVenta(Venta $venta): JsonResponse
    {
        return $this->disponible($venta);
    }

    public function storeVenta(StoreAplicacionCreditoRequest $request, Venta $venta): JsonResponse
    {
        return $this->store($request, $venta);
    }

    public function destroyVenta(Venta $venta, AplicacionCredito $aplicacion): JsonResponse
    {
        return $this->destroy($venta, $aplicacion);
    }

    // -----------------------------------------------------------------------------------
    // Compras (US4 — mismo servicio, que es agnóstico del tipo de comprobante)
    // -----------------------------------------------------------------------------------

    public function disponibleCompra(Compra $compra): JsonResponse
    {
        return $this->disponible($compra);
    }

    public function storeCompra(StoreAplicacionCreditoRequest $request, Compra $compra): JsonResponse
    {
        return $this->store($request, $compra);
    }

    public function destroyCompra(Compra $compra, AplicacionCredito $aplicacion): JsonResponse
    {
        return $this->destroy($compra, $aplicacion);
    }

    // -----------------------------------------------------------------------------------
    // Implementación común
    // -----------------------------------------------------------------------------------

    private function disponible(Model $comprobante): JsonResponse
    {
        $origenes = $this->credito->disponiblePara($comprobante);
        $disponibleTotal = round((float) $origenes->sum('disponible'), 2);
        $pendiente = $this->pendiente($comprobante);

        return response()->json([
            'ok' => true,
            'disponible_total' => $disponibleTotal,
            'saldo_pendiente' => $pendiente,
            'aplicable' => round(max(0, min($disponibleTotal, $pendiente)), 2),
            'origenes' => $origenes->map(fn (array $fila) => [
                'comprobante_id' => $fila['comprobante']->id,
                'comprobante_label' => $this->etiqueta($fila['comprobante']),
                'nota_credito_debito_id' => $fila['nota']?->id,
                'nota_label' => $this->etiquetaNota($fila['nota']),
                'fecha' => optional($fila['comprobante']->fecha_emision)->toDateString(),
                'disponible' => $fila['disponible'],
            ])->values(),
        ]);
    }

    private function store(StoreAplicacionCreditoRequest $request, Model $comprobante): JsonResponse
    {
        $datos = $request->validated();

        try {
            $aplicaciones = $this->credito->aplicar(
                $comprobante,
                (float) $datos['monto'],
                Carbon::parse($datos['fecha']),
                $datos['nota'] ?? null,
                isset($datos['origen_id']) ? (int) $datos['origen_id'] : null,
                $request->user()?->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'errors' => ['monto' => [$e->getMessage()]]], 422);
        }

        $comprobante->refresh();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Saldo a favor aplicado.',
            'aplicaciones' => $aplicaciones->map(fn (AplicacionCredito $a) => [
                'id' => $a->id,
                'origen_id' => $a->origen_id,
                'monto' => (float) $a->monto,
                'nota_credito_debito_id' => $a->nota_credito_debito_id,
            ])->values(),
            ...$this->estado($comprobante),
            'credito_disponible_restante' => $this->credito->disponibleTotalPara($comprobante),
        ], 201);
    }

    private function destroy(Model $comprobante, AplicacionCredito $aplicacion): JsonResponse
    {
        if ($aplicacion->destino_id !== $comprobante->id
            || $aplicacion->destino_type !== $comprobante->getMorphClass()) {
            abort(404);
        }

        $this->credito->anular($aplicacion);

        $comprobante->refresh();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Aplicación anulada.',
            ...$this->estado($comprobante),
        ]);
    }

    /** @return array<string, mixed> */
    private function estado(Model $comprobante): array
    {
        return $comprobante instanceof Venta
            ? ['a_cobrar' => $comprobante->aCobrar(), 'estado_cobro' => $comprobante->estadoCobro()]
            : ['a_pagar' => $comprobante->aPagar(), 'estado_pago' => $comprobante->estadoPago()];
    }

    private function pendiente(Model $comprobante): float
    {
        return $comprobante instanceof Venta ? $comprobante->aCobrar() : $comprobante->aPagar();
    }

    private function etiqueta(Model $comprobante): string
    {
        $tipo = $comprobante instanceof Venta ? 'Venta' : 'Compra';

        return trim($tipo.' '.($comprobante->nro_comprobante ?: $comprobante->id));
    }

    private function etiquetaNota(?\App\Models\NotaCreditoDebito $nota): ?string
    {
        if (! $nota) {
            return null;
        }

        $numero = $nota->comprobanteFiscal?->numero ?? $nota->nro_comprobante;

        return trim('NC '.($nota->tipo_comprobante ?? '').' '.($numero ?? $nota->id));
    }
}
