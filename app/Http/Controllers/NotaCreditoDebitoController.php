<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotaCreditoDebitoRequest;
use App\Http\Requests\UpdateNotaCreditoDebitoRequest;
use App\Models\Compra;
use App\Models\DatosEmpresa;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\AjustesPendientesNotaCreditoDebito;
use App\Services\Arca\EmisorComprobante;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\Excepciones\ArcaRechazoException;
use App\Services\Arca\Excepciones\CertificadoNoConfiguradoException;
use App\Services\Arca\MapeadorComprobante;
use App\Services\Stock\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** NC/ND (US4): wizard de 2 pasos sobre una venta, opcionalmente afecta stock. */
class NotaCreditoDebitoController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly EmisorComprobante $emisorComprobante,
        private readonly AjustesPendientesNotaCreditoDebito $ajustesPendientes,
    ) {}

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
        // FR-016 (spec 095): no se ofrece crear una nota sobre un comprobante dado de baja.
        // El binding ya filtra los soft-deleted, pero un 404 pelado no explica el motivo.
        if ($venta->trashed()) {
            return redirect()
                ->route('ventas.index')
                ->with('error', 'La venta fue eliminada: no se puede crear una NC/ND sobre ella.');
        }

        $CurrentPage = 'ventas';
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => $venta,
            'compra' => null,
            'notaCreditoDebito' => null,
            'depositos' => $depositos,
            // Spec 095: la nota nace como espejo del comprobante de origen.
            'cabeceraOrigen' => $this->ajustesPendientes->cabeceraComprobante($venta),
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
        // FR-016 (spec 095): idem Ventas — no se precarga desde un comprobante dado de baja.
        if ($compra->trashed()) {
            return redirect()
                ->route('compras.index')
                ->with('error', 'La compra fue eliminada: no se puede crear una NC/ND sobre ella.');
        }

        $CurrentPage = 'compras';
        $depositos = Deposito::orderBy('nombre')->get();

        return view('notas-credito-debito.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => null,
            'compra' => $compra,
            'notaCreditoDebito' => null,
            'depositos' => $depositos,
            // Spec 095: misma cabecera que en Ventas, con el Proveedor como tercero (FR-010).
            'cabeceraOrigen' => $this->ajustesPendientes->cabeceraComprobante($compra),
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

            $costosOriginales = $this->costosCongeladosDeLaVenta($venta);

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // NC repone stock (entrada); ND lo descuenta (salida) — research.md §9.
                $signo = $datos['tipo'] === 'credito' ? 1 : -1;

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create($this->atributosItemNota(
                        ['producto_id' => $producto->id] + $item, 'venta_original', $costosOriginales, true
                    ));

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
                    $nota->items()->create($this->atributosItemNota($item, 'nuevo', $costosOriginales, true));
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
            $qrDataUri = (new Builder)
                ->build(data: $url, size: 150)
                ->getDataUri();
        }

        $pdf = Pdf::loadView('notas-credito-debito.pdf', compact('notaCreditoDebito', 'qrDataUri', 'datosEmpresa'));

        return $pdf->stream('nota-'.$notaCreditoDebito->id.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /** US3: la NC/ND obtiene su propio CAE referenciando el comprobante original de la Venta. */
    private function emitirComprobanteFiscalNota($nota, Venta $venta, $comprobanteVenta): ?string
    {
        if (! FuncionAvanzada::activa('facturacion_electronica')) {
            return null;
        }

        $venta->load('cliente.condicionIva');

        $datos = [
            'tipo_comprobante' => $nota->tipo_comprobante,
            'tipo_nota' => $nota->tipo,
            'fecha' => $nota->fecha_emision,
            'cliente' => $venta->cliente?->datosFiscalesArca() ?? [],
            'neto' => round((float) $nota->monto / 1.21, 2),
            'iva' => round((float) $nota->monto - round((float) $nota->monto / 1.21, 2), 2),
            'total' => (float) $nota->monto,
            'comprobante_ajustado_id' => $comprobanteVenta->id,
            'comprobante_ajustado' => [
                'tipo' => (new MapeadorComprobante)->cbteTipo($comprobanteVenta->tipo_comprobante),
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

            // Una nota de COMPRA no tiene venta de origen de la que copiar costo: sus líneas
            // congelan el costo vigente, como cualquier línea nueva.
            $costosOriginales = [];

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // NC de compra (proveedor te acredita, devolución) descuenta stock; ND lo suma — inverso a Venta.
                $signo = $datos['tipo'] === 'credito' ? -1 : 1;

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create($this->atributosItemNota(
                        ['producto_id' => $producto->id] + $item, 'venta_original', $costosOriginales, false
                    ));

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
                    $nota->items()->create($this->atributosItemNota($item, 'nuevo', $costosOriginales, false));
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

            $costosOriginales = $this->costosCongeladosDeLaVenta($venta);

            if ($afectaStock) {
                $deposito = Deposito::findOrFail($datos['deposito_id']);
                // Mismo signo que store()/storeCompra() — venta y compra son inversas entre sí.
                $signo = $venta
                    ? ($datos['tipo'] === 'credito' ? 1 : -1)
                    : ($datos['tipo'] === 'credito' ? -1 : 1);

                foreach ($datos['items'] as $item) {
                    $producto = Producto::findOrFail($item['producto_id']);

                    $nota->items()->create($this->atributosItemNota(
                        ['producto_id' => $producto->id] + $item, 'venta_original', $costosOriginales, (bool) $venta
                    ));

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
                    $nota->items()->create($this->atributosItemNota($item, 'nuevo', $costosOriginales, (bool) $venta));
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
    public function destroy(Request $request, Venta $venta, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEliminacion($request, $notaCreditoDebito, $venta, null);
    }

    /** Idem para Compra. */
    public function destroyCompra(Request $request, Compra $compra, NotaCreditoDebito $notaCreditoDebito): JsonResponse
    {
        return $this->aplicarEliminacion($request, $notaCreditoDebito, null, $compra);
    }

    private function aplicarEliminacion(Request $request, NotaCreditoDebito $nota, ?Venta $venta, ?Compra $compra): JsonResponse
    {
        if ($nota->tieneCaeAprobado()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No se puede eliminar: la nota ya tiene un comprobante fiscal aprobado por ARCA. Cargá una nueva NC/ND que la ajuste.',
            ], 409);
        }

        if ($nota->tieneCreditoAplicado()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'La Nota de Crédito tiene saldo aplicado a otros comprobantes. Anulá primero esas aplicaciones.',
            ], 422);
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

    /**
     * Costos congelados de la venta de origen, por `producto_id` y en orden de línea (spec 075).
     *
     * Cada entrada de la cola es `['cantidad' => x, 'costo' => y]`, **no** un costo suelto, y la
     * razón la encontró una prueba en navegador: el formulario de NC/ND **agrupa por producto**
     * los ítems de la venta original, así que una sola línea de nota con cantidad 2 puede estar
     * revirtiendo dos líneas de venta con costos congelados distintos. Con una cola de costos
     * sueltos se consumía uno solo, la NC revertía 2 × el primer costo y anular la venta dejaba un
     * residuo en el Resultado — justo lo que FR-008 prohíbe.
     *
     * Un `null` en la cola es un valor válido y significativo: la venta original es histórica y no
     * tiene costo congelado, así que la nota que la revierte también cae al promedio de compras y
     * el neto entre las dos sigue dando cero.
     *
     * @return array<int, list<array{cantidad: float, costo: float|null}>>
     */
    private function costosCongeladosDeLaVenta(?Venta $venta): array
    {
        if (! $venta) {
            return [];
        }

        $cola = [];

        foreach ($venta->items()->orderBy('id')->get() as $item) {
            if ($item->producto_id === null) {
                continue;
            }

            $cola[(int) $item->producto_id][] = [
                'cantidad' => abs((float) $item->cantidad),
                'costo' => $item->costo_unitario === null ? null : (float) $item->costo_unitario,
            ];
        }

        return $cola;
    }

    /**
     * Atributos de una línea de nota, con su costo congelado resuelto (spec 075, `data-model.md §2`).
     *
     * Estaba repetido en los seis puntos de creación de los tres métodos del controlador; con la
     * regla del costo encima, mantener seis copias sincronizadas era cuestión de tiempo hasta que
     * una quedara atrás.
     *
     * El costo se guarda **siempre en positivo**: el signo de la nota lo aporta la cantidad en la
     * expresión del informe, no el costo (invariante I5). `null` sólo aparece heredado de una
     * venta histórica; una línea nueva sin producto o con producto sin costo congela `0`.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, list<array{cantidad: float, costo: float|null}>>  $costosOriginales  cola por producto, consumida acá
     * @return array<string, mixed>
     */
    /**
     * @param bool|null $esVenta Spec 096: null = no persistir referencia de línea (nota sin
     *   comprobante de origen fiable en este punto, ej. líneas 'nuevo' agregadas a mano); true/false
     *   decide si `item_origen_id` (si viene) se guarda en `venta_item_id` o `compra_item_id`.
     */
    private function atributosItemNota(array $item, string $origen, array &$costosOriginales, ?bool $esVenta = null): array
    {
        $productoId = $item['producto_id'] ?? null;
        $cantidad = $item['cantidad'] ?? 1;
        $itemOrigenId = $item['item_origen_id'] ?? null;

        return [
            'producto_id' => $productoId,
            // Spec 096 (FR-004): referencia a la línea puntual del comprobante que esta línea de
            // la nota ajusta. Sólo se persiste si el front la mandó (precarga desde items
            // disponibles) — una línea agregada a mano por el usuario, sin pasar por la precarga,
            // no la trae y ese producto queda en modo agregado/fallback (FR-006) hasta que sí se
            // use una con referencia.
            'venta_item_id' => $esVenta === true ? $itemOrigenId : null,
            'compra_item_id' => $esVenta === false ? $itemOrigenId : null,
            'cantidad' => $cantidad,
            'precio' => $item['precio'] ?? 0,
            'costo_unitario' => $this->costoCongeladoDeLaNota($productoId, (float) $cantidad, $costosOriginales),
            'descuento_pct' => $item['descuento_pct'] ?? 0,
            'iva_pct' => $item['iva_pct'] ?? null,
            'origen' => $origen,
        ];
    }

    /**
     * Costo unitario a congelar en una línea de nota, consumiendo la cola de la venta de origen.
     *
     * Consume tantas líneas de la venta como haga falta para cubrir `$cantidad` y devuelve el
     * **promedio ponderado** de los costos consumidos. Con eso el CMV que la nota revierte iguala
     * exactamente el que aportaron esas líneas, aunque la nota las traiga agrupadas en una sola.
     *
     * Si la cola se agota antes de cubrir la cantidad, el remanente se valúa al costo vigente
     * (es una cantidad que no salió de la venta original). Si todo lo consumido era `null` se
     * devuelve `null` y la línea cae al mismo fallback que la venta que revierte; en el caso
     * mixto —una venta histórica que después se editó agregando una línea nueva— se promedia
     * sobre las que sí tienen costo, que es lo más cerca del valor real que se puede llegar.
     *
     * @param  array<int, list<array{cantidad: float, costo: float|null}>>  $costosOriginales
     */
    private function costoCongeladoDeLaNota(mixed $productoId, float $cantidad, array &$costosOriginales): ?float
    {
        if (empty($productoId)) {
            return 0.0;
        }

        $productoId = (int) $productoId;
        $pendiente = abs($cantidad);
        $cantidadValuada = 0.0;
        $costoAcumulado = 0.0;
        $huboNull = false;

        // La condición NO mira `origen`, aunque `data-model.md §2` esté redactado en función de
        // él: en este controlador `origen` no distingue "revierte la venta" de "ajuste nuevo",
        // distingue si la nota afecta stock o no —una NC que anula una venta entera sin tocar
        // stock guarda sus líneas como `nuevo`—. Keyear la regla en `origen` hacía que ese caso,
        // que es el común, tomara el costo de hoy y dejara un residuo en el Resultado.
        while ($pendiente > 0 && ($costosOriginales[$productoId] ?? []) !== []) {
            $linea = $costosOriginales[$productoId][0];
            $toma = $linea['cantidad'] <= 0 ? $pendiente : min($pendiente, $linea['cantidad']);

            if ($linea['costo'] === null) {
                $huboNull = true;
            } else {
                $cantidadValuada += $toma;
                $costoAcumulado += $toma * $linea['costo'];
            }

            $pendiente -= $toma;
            $restante = $linea['cantidad'] - $toma;

            if ($restante > 0.0000001) {
                $costosOriginales[$productoId][0]['cantidad'] = $restante;
            } else {
                array_shift($costosOriginales[$productoId]);
            }
        }

        if ($cantidadValuada > 0) {
            return round($costoAcumulado / $cantidadValuada, 2);
        }

        // Todo lo consumido venía de una venta histórica sin costo congelado: se hereda el `null`
        // para que la nota caiga al mismo fallback que la venta.
        if ($huboNull) {
            return null;
        }

        $costo = Producto::whereKey($productoId)->value('costo');

        return $costo === null ? 0.0 : round((float) $costo, 2);
    }
}
