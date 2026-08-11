<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotaCreditoDebitoRequest;
use App\Http\Requests\UpdateNotaCreditoDebitoRequest;
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

    /** Spec 059 (T005): página completa de creación (Ventas) — GET, sólo navegación. */
    public function create(Venta $venta)
    {
        $CurrentPage = 'ventas';
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => $venta,
            'compra' => null,
            'notaCreditoDebito' => null,
            'depositos' => $depositos,
        ]);
    }

    /** Spec 059 (T005): página completa de edición (Ventas) — GET, precarga desde la nota existente. */
    public function edit(Venta $venta, NotaCreditoDebito $notaCreditoDebito)
    {
        $CurrentPage = 'ventas';
        $notaCreditoDebito->load('items.producto');
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => $venta,
            'compra' => null,
            'notaCreditoDebito' => $notaCreditoDebito,
            'depositos' => $depositos,
        ]);
    }

    /** Spec 059 (T005): página completa de creación (Compras) — GET, sólo navegación. */
    public function createCompra(Compra $compra)
    {
        $CurrentPage = 'compras';
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => null,
            'compra' => $compra,
            'notaCreditoDebito' => null,
            'depositos' => $depositos,
        ]);
    }

    /** Spec 059 (T005): página completa de edición (Compras) — GET, precarga desde la nota existente. */
    public function editCompra(Compra $compra, NotaCreditoDebito $notaCreditoDebito)
    {
        $CurrentPage = 'compras';
        $notaCreditoDebito->load('items.producto');
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => null,
            'compra' => $compra,
            'notaCreditoDebito' => $notaCreditoDebito,
            'depositos' => $depositos,
        ]);
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
                'tipo_comprobante' => $datos['tipo_comprobante'] ?? $venta->tipo_comprobante,
                'nro_comprobante' => $datos['nro_comprobante'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'descuento_general_tipo' => $datos['descuento_general_tipo'] ?? 'porcentaje',
                'descuento_general_pct' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? null : ($datos['descuento_general_pct'] ?? null),
                'descuento_general_monto' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
                'impuestos' => $datos['conceptos'] ?? [],
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
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'venta_original',
                    ]);

                    $this->stockService->ajustar(
                        $producto,
                        null,
                        $deposito,
                        $signo * (float) $item['cantidad'],
                        ($datos['tipo'] === 'credito' ? 'Nota de Crédito' : 'Nota de Débito').' venta '.$venta->nro_comprobante,
                        $request->user(),
                        null,
                        $nota,
                    );
                }
            } elseif (! empty($datos['items'])) {
                // Sin stock: no hay StockService de por medio, pero igual se persiste el ítem
                // (precio/IVA/desc.) para que la edición pueda reconstruirlo — antes se perdía
                // en silencio y el form de editar quedaba con el IVA en "Elegir" (detectado en
                // QA manual 11/08/2026).
                foreach ($datos['items'] as $item) {
                    $nota->items()->create([
                        'producto_id' => $item['producto_id'] ?? null,
                        'cantidad' => $item['cantidad'] ?? 1,
                        'precio' => $item['precio'] ?? 0,
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'nuevo',
                    ]);
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
        $notaCreditoDebito->load([
            'comprobanteFiscal.puntoVenta',
            'venta.cliente', 'venta.comprobanteFiscal',
            'compra.proveedor', 'compra.comprobanteFiscal',
            'items.producto',
        ]);
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
                'tipo_comprobante' => $datos['tipo_comprobante'] ?? $compra->tipo_comprobante,
                'nro_comprobante' => $datos['nro_comprobante'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'descuento_general_tipo' => $datos['descuento_general_tipo'] ?? 'porcentaje',
                'descuento_general_pct' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? null : ($datos['descuento_general_pct'] ?? null),
                'descuento_general_monto' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
                'impuestos' => $datos['conceptos'] ?? [],
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
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'venta_original',
                    ]);

                    $this->stockService->ajustar(
                        $producto,
                        null,
                        $deposito,
                        $signo * (float) $item['cantidad'],
                        ($datos['tipo'] === 'credito' ? 'Nota de Crédito' : 'Nota de Débito').' compra '.$compra->nro_comprobante,
                        $request->user(),
                        null,
                        $nota,
                    );
                }
            } elseif (! empty($datos['items'])) {
                // Sin stock: idem store() — persistir el ítem para que la edición lo reconstruya.
                foreach ($datos['items'] as $item) {
                    $nota->items()->create([
                        'producto_id' => $item['producto_id'] ?? null,
                        'cantidad' => $item['cantidad'] ?? 1,
                        'precio' => $item['precio'] ?? 0,
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'nuevo',
                    ]);
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

    /** US1 (spec 057): edita una NC/ND de Venta — bloquea si tiene CAE, revierte y reaplica stock. */
    public function update(UpdateNotaCreditoDebitoRequest $request, Venta $venta, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEdicion($request, $notaCreditoDebito, $venta, null);
    }

    /** Idem para Compra. */
    public function updateCompra(UpdateNotaCreditoDebitoRequest $request, Compra $compra, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEdicion($request, $notaCreditoDebito, null, $compra);
    }

    private function aplicarEdicion(UpdateNotaCreditoDebitoRequest $request, NotaCreditoDebito $nota, ?Venta $venta, ?Compra $compra): JsonResponse
    {
        if ($nota->tieneCaeAprobado()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No se puede editar: la nota ya tiene un comprobante fiscal aprobado por ARCA. Cargá una nueva NC/ND que la ajuste.',
            ], 409);
        }

        $datos = $request->validated();
        $afectaStock = $datos['afecta_stock'] ?? false;

        DB::transaction(function () use ($datos, $nota, $venta, $compra, $afectaStock, $request) {
            $this->stockService->revertirNotaCreditoDebito($nota, $request->user());

            $notaAjustadaId = ($datos['documento_ajusta']['tipo'] ?? null) === 'nota'
                ? ($datos['documento_ajusta']['nota_ajustada_id'] ?? null)
                : null;

            $nota->update([
                'afecta_stock' => $afectaStock,
                'mes_imputacion' => $datos['mes_imputacion'],
                'fecha_emision' => $datos['fecha_emision'],
                'monto' => $datos['monto'],
                'tipo_comprobante' => $datos['tipo_comprobante'] ?? $nota->tipo_comprobante,
                'nro_comprobante' => $datos['nro_comprobante'] ?? null,
                'nota_ajustada_id' => $notaAjustadaId,
                'descripcion' => $datos['descripcion'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'descuento_general_tipo' => $datos['descuento_general_tipo'] ?? 'porcentaje',
                'descuento_general_pct' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? null : ($datos['descuento_general_pct'] ?? null),
                'descuento_general_monto' => ($datos['descuento_general_tipo'] ?? 'porcentaje') === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
                'impuestos' => $datos['conceptos'] ?? [],
            ]);

            $nota->items()->delete();

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // Mismo signo que store()/storeCompra() — venta y compra son inversas entre sí.
                $signo = $venta
                    ? ($datos['tipo'] === 'credito' ? 1 : -1)
                    : ($datos['tipo'] === 'credito' ? -1 : 1);

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create([
                        'producto_id' => $producto->id,
                        'cantidad' => $item['cantidad'],
                        'precio' => $item['precio'] ?? 0,
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'venta_original',
                    ]);

                    $this->stockService->ajustar(
                        $producto,
                        null,
                        $deposito,
                        $signo * (float) $item['cantidad'],
                        ($datos['tipo'] === 'credito' ? 'Nota de Crédito' : 'Nota de Débito').' '.($venta ? 'venta' : 'compra').' '.($venta?->nro_comprobante ?? $compra?->nro_comprobante),
                        $request->user(),
                        null,
                        $nota,
                    );
                }
            } elseif (! empty($datos['items'])) {
                foreach ($datos['items'] as $item) {
                    $nota->items()->create([
                        'producto_id' => $item['producto_id'] ?? null,
                        'cantidad' => $item['cantidad'] ?? 1,
                        'precio' => $item['precio'] ?? 0,
                        'descuento_pct' => $item['descuento_pct'] ?? 0,
                        'iva_pct' => $item['iva_pct'] ?? null,
                        'origen' => 'nuevo',
                    ]);
                }
            }
        });

        $nota->refresh();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Nota actualizada correctamente.',
            'nota' => $nota,
            'a_cobrar' => $venta?->fresh()->aCobrar(),
            'a_pagar' => $compra?->fresh()->aPagar(),
            'comprobante_fiscal' => $nota->comprobanteFiscal()->first(),
        ]);
    }

    /** US2 (spec 057): elimina (soft delete) una NC/ND de Venta — bloquea por CAE o por cadena. */
    public function destroy(\Illuminate\Http\Request $request, Venta $venta, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEliminacion($request, $notaCreditoDebito, $venta, null);
    }

    /** Idem para Compra. */
    public function destroyCompra(\Illuminate\Http\Request $request, Compra $compra, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEliminacion($request, $notaCreditoDebito, null, $compra);
    }

    private function aplicarEliminacion(\Illuminate\Http\Request $request, NotaCreditoDebito $nota, ?Venta $venta, ?Compra $compra): JsonResponse
    {
        if ($nota->tieneCaeAprobado()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No se puede eliminar: la nota ya tiene un comprobante fiscal aprobado por ARCA. Cargá una nueva NC/ND que la ajuste.',
            ], 409);
        }

        $dependientes = $nota->notasQueLaAjustan()->pluck('id');
        if ($dependientes->isNotEmpty()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No se puede eliminar: las notas #'.$dependientes->implode(', #').' la ajustan a ésta. Eliminalas primero.',
            ], 409);
        }

        DB::transaction(function () use ($nota, $request) {
            $this->stockService->revertirNotaCreditoDebito($nota, $request->user());
            $nota->delete();
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Nota eliminada correctamente.',
            'a_cobrar' => $venta?->fresh()->aCobrar(),
            'a_pagar' => $compra?->fresh()->aPagar(),
        ]);
    }
}
