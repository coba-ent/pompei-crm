<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotaCreditoDebitoRequest;
use App\Models\Compra;
use App\Models\DatosEmpresa;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Arca\EmisorComprobante;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\Excepciones\ArcaRechazoException;
use App\Services\Arca\Excepciones\CertificadoNoConfiguradoException;
use App\Services\AjustesPendientesNotaCreditoDebito;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** NC/ND (US4): wizard de 2 pasos sobre una venta, opcionalmente afecta stock. */
class NotaCreditoDebitoController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly EmisorComprobante $emisorComprobante,
        private readonly AjustesPendientesNotaCreditoDebito $ajustesPendientes,
    ) {
    }

    /** Spec 045 (T006): productos del comprobante original con su cantidad pendiente de ajuste. */
    public function itemsDisponiblesVenta(Venta $venta): JsonResponse
    {
        return response()->json(['data' => $this->ajustesPendientes->itemsDisponibles($venta)]);
    }

    /** Spec 045 (T006): idem para Compra. */
    public function itemsDisponiblesCompra(Compra $compra): JsonResponse
    {
        return response()->json(['data' => $this->ajustesPendientes->itemsDisponibles($compra)]);
    }

    public function store(StoreNotaCreditoDebitoRequest $request, Venta $venta): JsonResponse
    {
        $datos = $request->validated();
        $afectaStock = $datos['afecta_stock'] ?? false;

        $nota = DB::transaction(function () use ($datos, $venta, $afectaStock, $request) {
            $nota = $venta->notasCreditoDebito()->create([
                'tipo' => $datos['tipo'],
                'afecta_stock' => $afectaStock,
                'mes_imputacion' => $datos['mes_imputacion'],
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

        $arcaError = null;
        $comprobanteVenta = $venta->comprobanteFiscal;
        if ($comprobanteVenta && $comprobanteVenta->aprobado()) {
            $arcaError = $this->emitirComprobanteFiscalNota($nota, $venta, $comprobanteVenta);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Nota de '.($datos['tipo'] === 'credito' ? 'Crédito' : 'Débito').' creada correctamente.',
            'nota' => $nota,
            'a_cobrar' => $venta->aCobrar(),
            'comprobante_fiscal' => $nota->comprobanteFiscal()->first(),
            'arca_error' => $arcaError,
        ], 201);
    }

    /** US1 (spec 039): documento imprimible con CAE propio y referencia al comprobante ajustado. */
    public function pdf(NotaCreditoDebito $notaCreditoDebito)
    {
        $notaCreditoDebito->load(['comprobanteFiscal.puntoVenta', 'venta.cliente', 'venta.comprobanteFiscal']);
        $datosEmpresa = DatosEmpresa::instancia();

        $qrDataUri = null;
        if ($url = $notaCreditoDebito->comprobanteFiscal?->urlQrAfip()) {
            $qrDataUri = (new \Endroid\QrCode\Builder\Builder())
                ->build(data: $url, size: 150)
                ->getDataUri();
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('notas-credito-debito.pdf', compact('notaCreditoDebito', 'qrDataUri', 'datosEmpresa'));

        return $pdf->stream('nota-'.$notaCreditoDebito->id.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /** US3: la NC/ND obtiene su propio CAE referenciando el comprobante original de la Venta. */
    private function emitirComprobanteFiscalNota($nota, Venta $venta, $comprobanteVenta): ?string
    {
        if (! \App\Models\FuncionAvanzada::activa('facturacion_electronica')) {
            return null;
        }

        $venta->load('cliente.condicionIva');

        $datos = [
            'tipo_comprobante' => $nota->tipo_comprobante,
            'tipo_nota' => $nota->tipo,
            'fecha' => $nota->fecha_emision,
            'cliente' => [
                'cuit' => $venta->cliente?->cuit,
                'dni' => $venta->cliente?->tipo_documento === 'DNI' ? $venta->cliente?->cuit : null,
                'condicion_iva_codigo' => $venta->cliente?->condicionIva?->codigo_afip,
            ],
            'neto' => round((float) $nota->monto / 1.21, 2),
            'iva' => round((float) $nota->monto - round((float) $nota->monto / 1.21, 2), 2),
            'total' => (float) $nota->monto,
            'comprobante_ajustado_id' => $comprobanteVenta->id,
            'comprobante_ajustado' => [
                'tipo' => (new \App\Services\Arca\MapeadorComprobante())->cbteTipo($comprobanteVenta->tipo_comprobante),
                'punto_venta' => $comprobanteVenta->puntoVenta?->numero ?? 0,
                'numero' => (int) last(explode('-', $comprobanteVenta->numero ?? '0-0')),
            ],
        ];

        try {
            $this->emisorComprobante->emitir($nota, $datos);

            return null;
        } catch (CertificadoNoConfiguradoException) {
            return null;
        } catch (ArcaRechazoException|ArcaNoDisponibleException $e) {
            return $e->getMessage();
        }
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
                'mes_imputacion' => $datos['mes_imputacion'],
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
