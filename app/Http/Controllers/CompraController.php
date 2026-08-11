<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompraRequest;
use App\Http\Requests\StorePagoRequest;
use App\Http\Requests\StoreRetencionRequest;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\ConfiguracionVentas;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Models\Remito;
use App\Models\TipoProducto;
use App\Services\Egresos\Pagos;
use App\Services\Egresos\StockDeCompra;
use App\Services\Ingresos\CalculoComprobante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/** Compras (US1): listado (KPIs), formulario de página completa, detalle con pagos/retenciones/NC-ND. */
class CompraController extends Controller
{
    public function __construct(
        private readonly CalculoComprobante $calculo,
        private readonly Pagos $pagos,
        private readonly StockDeCompra $stockDeCompra,
    ) {
    }

    public function index(Request $request)
    {
        $CurrentPage = 'compras';
        $kpis = $this->kpis($request);
        $categoriasCompra = Categoria::compra()->activas()->orderBy('nombre')->get(['id', 'nombre']);
        $etiquetas = \App\Models\Etiqueta::orderBy('nombre')->get(['id', 'nombre']);
        $cuentasTesoreria = CuentaTesoreria::orderBy('nombre')->get(['id', 'nombre']);
        $usuarios = \App\Models\User::orderBy('name')->get(['id', 'name']);
        $depositos = Deposito::activos()->orderBy('nombre')->get(['id', 'nombre']);

        return view('compras.index', compact('CurrentPage', 'kpis', 'categoriasCompra', 'etiquetas', 'cuentasTesoreria', 'usuarios', 'depositos'));
    }

    /** Barra de 5 KPIs del listado (informe §2.4): Cantidad, Pagado, A Pagar, Vencido, Total. */
    /**
     * KPIs del listado, en **una sola consulta agregada** — mismo criterio que VentaController.
     *
     * Traer todas las compras a memoria y llamar `aPagar()` por fila dispara 3 consultas por
     * compra. Con datos de prueba no se nota; con el histórico de Contagram (2.526 compras) son
     * ~7.500 consultas en cada carga de la pantalla. Es el mismo bug que dejó Ventas en más de un
     * minuto tras importar el histórico (10/08/2026), corregido acá antes de que se manifieste.
     *
     * Mantiene la definición del modelo: `A Pagar = Total + Σ ND − Σ NC − Pagado`.
     */
    private function kpis(Request $request): array
    {
        $pagado = 'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0)';
        $nc = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0)";
        $nd = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0)";
        $aPagar = "(compras.total + {$nd} - {$nc} - {$pagado})";

        $r = $this->aplicarFiltros(Compra::query(), $request)
            ->selectRaw("
                COUNT(*) AS cantidad,
                COALESCE(SUM(compras.total), 0) AS total,
                COALESCE(SUM({$pagado}), 0) AS pagado,
                COALESCE(SUM({$aPagar}), 0) AS a_pagar,
                COALESCE(SUM(CASE
                    WHEN compras.fecha_vto_pago IS NOT NULL
                     AND compras.fecha_vto_pago < CURDATE()
                     AND {$aPagar} > 0.005
                    THEN {$aPagar} ELSE 0 END), 0) AS vencido
            ")
            ->first();

        return [
            'cantidad' => (int) $r->cantidad,
            'pagado' => round((float) $r->pagado, 2),
            'a_pagar' => round((float) $r->a_pagar, 2),
            'vencido' => round((float) $r->vencido, 2),
            'total' => round((float) $r->total, 2),
        ];
    }

    private function queryFiltrada(Request $request): Builder
    {
        $query = Compra::query()->with(['proveedor:id,nombre,cuit,telefono,email', 'categoria:id,nombre', 'pagos.cuentaTesoreria:id,nombre']);

        return $this->aplicarFiltros($query, $request);
    }

    /** Filtros del listado, aplicados tanto sobre el listado (data()) como sobre los KPIs (kpis()). */
    private function aplicarFiltros(Builder $query, Request $request): Builder
    {
        if ($request->filled('id')) {
            $query->where('id', (int) $request->input('id'));
        }
        if ($request->filled('proveedor_id')) {
            $query->whereIn('proveedor_id', (array) $request->input('proveedor_id'));
        }
        if ($request->filled('categoria_id')) {
            $query->whereIn('categoria_id', (array) $request->input('categoria_id'));
        }
        if ($request->filled('estado_pago')) {
            $pagado = 'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0)';
            $nc = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0)";
            $nd = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0)";
            $aPagar = "(compras.total + {$nd} - {$nc} - {$pagado})";
            match ($request->input('estado_pago')) {
                'pagado' => $query->whereRaw("{$pagado} > 0")->whereRaw("{$aPagar} <= 0.005"),
                'parcial' => $query->whereRaw("{$pagado} > 0")->whereRaw("{$aPagar} > 0.005"),
                // Mismo criterio que la card KPI "Vencido": vto. pasado y todavía queda saldo.
                'vencido' => $query->whereNotNull('fecha_vto_pago')->whereDate('fecha_vto_pago', '<', now())->whereRaw("{$aPagar} > 0.005"),
                default => $query->whereRaw("{$pagado} <= 0"),
            };
        }
        if ($request->filled('factura_buscar')) {
            $kw = $request->input('factura_buscar');
            $query->where(fn (Builder $q) => $q->where('tipo_comprobante', 'like', "%{$kw}%")
                ->orWhereHas('comprobanteFiscal', fn (Builder $qq) => $qq->where('numero', 'like', "%{$kw}%")));
        }
        if ($request->filled('etiqueta_id')) {
            $query->whereHas('etiquetas', fn (Builder $q) => $q->whereIn('etiquetas.id', (array) $request->input('etiqueta_id')));
        }
        if ($request->has('facturado')) {
            $request->input('facturado') === '1'
                ? $query->whereHas('comprobanteFiscal')
                : $query->whereDoesntHave('comprobanteFiscal');
        }
        if ($request->filled('medio_pago_id')) {
            $query->whereHas('pagos', fn (Builder $q) => $q->where('cuenta_tesoreria_id', $request->input('medio_pago_id')));
        }
        if ($request->filled('usuario_id')) {
            $query->whereIn('creado_por_id', (array) $request->input('usuario_id'));
        }
        if ($request->filled('nota_interna')) {
            $query->where('nota_interna', 'like', '%'.$request->input('nota_interna').'%');
        }
        if ($request->filled('deposito_id')) {
            $query->where('deposito_id', $request->input('deposito_id'));
        }
        if ($request->filled('servicio_desde')) {
            $query->whereDate('servicio_desde', '>=', $request->input('servicio_desde'));
        }
        if ($request->filled('servicio_hasta')) {
            $query->whereDate('servicio_hasta', '<=', $request->input('servicio_hasta'));
        }
        if ($request->filled('emision_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->input('emision_desde'));
        }
        if ($request->filled('emision_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->input('emision_hasta'));
        }
        if ($request->filled('vencimiento_desde')) {
            $query->whereDate('fecha_vto_pago', '>=', $request->input('vencimiento_desde'));
        }
        if ($request->filled('vencimiento_hasta')) {
            $query->whereDate('fecha_vto_pago', '<=', $request->input('vencimiento_hasta'));
        }

        return $query;
    }

    /** KPIs recalculados contra los mismos filtros del listado (AJAX, panel de Filtros). */
    public function kpisData(Request $request): JsonResponse
    {
        return response()->json($this->kpis($request));
    }

    public function data(Request $request): JsonResponse
    {
        $query = $this->queryFiltrada($request)->with('etiquetas:id,nombre');

        return DataTables::eloquent($query)
            ->addColumn('acciones', fn (Compra $c) => view('compras._row_actions', ['compra' => $c])->render())
            ->addColumn('estado_pago', fn (Compra $c) => $c->estadoPago())
            ->addColumn('proveedor', fn (Compra $c) => optional($c->proveedor)->nombre)
            ->addColumn('categoria', fn (Compra $c) => optional($c->categoria)->nombre)
            ->addColumn('pagado', fn (Compra $c) => $c->pagado())
            ->addColumn('a_pagar', fn (Compra $c) => $c->aPagar())
            ->addColumn('medio_de_pago', fn (Compra $c) => optional($c->pagos->last()?->cuentaTesoreria)->nombre)
            ->addColumn('etiquetas', fn (Compra $c) => $c->etiquetas->pluck('nombre')->implode(', '))
            ->addColumn('cuit', fn (Compra $c) => optional($c->proveedor)->cuit)
            ->addColumn('telefono', fn (Compra $c) => optional($c->proveedor)->telefono)
            ->addColumn('mail', fn (Compra $c) => optional($c->proveedor)->email)
            ->editColumn('fecha_emision', fn (Compra $c) => optional($c->fecha_emision)->format('d/m/Y'))
            ->editColumn('fecha_vto_pago', fn (Compra $c) => optional($c->fecha_vto_pago)->format('d/m/Y'))
            ->editColumn('subtotal_sin_descuento', fn (Compra $c) => (float) $c->subtotal_sin_descuento)
            ->editColumn('descuento', fn (Compra $c) => (float) $c->descuento)
            ->editColumn('subtotal_con_descuento', fn (Compra $c) => (float) $c->subtotal_con_descuento)
            ->editColumn('total', fn (Compra $c) => (float) $c->total)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    public function create()
    {
        $CurrentPage = 'compras';
        $submitToken = (string) \Illuminate\Support\Str::uuid();

        // Defaults de Configuración & Ajustes → Ventas (spec 043): sección "Compras", mismo
        // criterio que VentaController@create — sólo alta nueva, sólo catálogos vigentes/activos.
        $configuracionVentas = ConfiguracionVentas::first();
        $defaults = null;
        if ($configuracionVentas) {
            $categoriaCompraDefault = $configuracionVentas->categoria_compra_id
                ? Categoria::compra()->activas()->find($configuracionVentas->categoria_compra_id)
                : null;
            $depositoDefault = $configuracionVentas->deposito_compra_id
                ? Deposito::activos()->find($configuracionVentas->deposito_compra_id)
                : null;

            $defaults = [
                'categoriaId' => $categoriaCompraDefault?->id,
                'depositoId' => $depositoDefault?->id,
                'tipoComprobante' => $configuracionVentas->tipo_comprobante_compra,
                'fechaVtoPago' => $configuracionVentas->dias_vto_pago_compra !== null
                    ? now()->addDays($configuracionVentas->dias_vto_pago_compra)->format('Y-m-d')
                    : null,
                'nroComprobanteSugerido' => Compra::siguienteNroComprobante($configuracionVentas->tipo_comprobante_compra ?? 'B'),
            ];
        } else {
            $defaults = ['nroComprobanteSugerido' => Compra::siguienteNroComprobante('B')];
        }

        return view('compras.form', [
            'CurrentPage' => $CurrentPage,
            'compra' => null,
            'submitToken' => $submitToken,
            'defaults' => $defaults,
            'categoriasCompra' => Categoria::compra()->activas()->orderBy('nombre')->get(),
            'depositos' => Deposito::activos()->orderBy('nombre')->get(),
            // Catálogos para los modales Ver/Editar de Producto reutilizados desde el
            // detalle de la Compra (spec 052).
            'tiposProducto' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'listasPrecioProductos' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(StoreCompraRequest $request): JsonResponse
    {
        $datos = $request->validated();

        if (Compra::withTrashed()->where('submit_token', $datos['submit_token'])->exists()) {
            $existente = Compra::withTrashed()->where('submit_token', $datos['submit_token'])->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'Compra '.$existente->nro_comprobante.' creada con éxito.',
                'compra' => $existente,
                'redirect' => route('compras.show', $existente),
            ], 201);
        }

        $compra = DB::transaction(function () use ($datos) {
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);

            $compra = Compra::create([
                'proveedor_id' => $datos['proveedor_id'],
                'creado_por_id' => auth()->id(),
                'categoria_id' => $datos['categoria_id'] ?? null,
                'deposito_id' => $datos['deposito_id'],
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_vto_pago' => $datos['fecha_vto_pago'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'mes_imputacion_iva' => $datos['mes_imputacion_iva'] ?? null,
                'tipo_comprobante' => $datos['tipo_comprobante'] ?? null,
                'nro_comprobante' => $datos['nro_comprobante'],
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento_general_pct' => $datos['descuento_general_pct'] ?? null,
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_interna' => $datos['nota_interna'] ?? null,
                'submit_token' => $datos['submit_token'],
            ]);

            $this->guardarItems($compra, $resultado['items']);
            $this->guardarConceptos($compra, $datos['conceptos'] ?? []);

            $this->stockDeCompra->aplicarAlta($compra->load('items.producto'));

            $this->registrarComprobanteFiscalProveedor($compra, $datos);

            return $compra;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Compra '.$compra->nro_comprobante.' creada con éxito.',
            'compra' => $compra,
            'redirect' => route('compras.show', $compra),
        ], 201);
    }

    /**
     * FR-015: el CAE de una Compra lo declara el Proveedor en su propio comprobante — este CRM
     * sólo registra esos datos (sin llamar a EmisorComprobante::emitir()).
     */
    private function registrarComprobanteFiscalProveedor(Compra $compra, array $datos): void
    {
        if (empty($datos['cae_proveedor']) || ! \App\Models\FuncionAvanzada::activa('facturacion_electronica')) {
            return;
        }

        $numero = trim(($datos['punto_venta_proveedor'] ?? '').'-'.($datos['numero_comprobante_proveedor'] ?? ''), '-') ?: null;

        ComprobanteFiscal::create([
            'comprobantable_type' => Compra::class,
            'comprobantable_id' => $compra->id,
            'punto_venta_id' => null,
            'tipo_comprobante' => $datos['tipo_comprobante'] ?? 'A',
            'numero' => $numero,
            'cae' => $datos['cae_proveedor'],
            'cae_vencimiento' => $datos['cae_vencimiento_proveedor'] ?? null,
            'estado' => 'aprobado',
        ]);
    }

    public function edit(Compra $compra)
    {
        $CurrentPage = 'compras';
        $compra->load(['items', 'conceptos', 'proveedor', 'categoria', 'deposito']);
        $categoriasCompra = Categoria::compra()->activas()->orderBy('nombre')->get();
        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $tiposProducto = TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $listasPrecioProductos = ListaPrecio::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        return view('compras.form', compact('CurrentPage', 'compra', 'categoriasCompra', 'depositos', 'tiposProducto', 'proveedores', 'listasPrecioProductos'));
    }

    public function update(\App\Http\Requests\UpdateCompraRequest $request, Compra $compra): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $compra) {
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);
            $itemsAnteriores = $compra->items()->with('producto')->get();
            $depositoAnteriorId = $compra->deposito_id;

            $compra->update([
                'proveedor_id' => $datos['proveedor_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'deposito_id' => $datos['deposito_id'],
                'nro_comprobante' => $datos['nro_comprobante'],
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_vto_pago' => $datos['fecha_vto_pago'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'mes_imputacion_iva' => $datos['mes_imputacion_iva'] ?? null,
                'tipo_comprobante' => $datos['tipo_comprobante'] ?? $compra->tipo_comprobante,
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento_general_pct' => $datos['descuento_general_pct'] ?? null,
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_interna' => $datos['nota_interna'] ?? null,
            ]);

            $compra->items()->delete();
            $compra->conceptos()->delete();
            $this->guardarItems($compra, $resultado['items']);
            $this->guardarConceptos($compra, $datos['conceptos'] ?? []);

            $depositoAnterior = $depositoAnteriorId ? Deposito::find($depositoAnteriorId) : null;
            $this->stockDeCompra->reaplicarPorEdicion($compra->load('items.producto'), $itemsAnteriores, $depositoAnterior);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Compra '.$compra->nro_comprobante.' actualizada con éxito.',
            'compra' => $compra->fresh(),
        ]);
    }

    public function destroy(Compra $compra): JsonResponse
    {
        $compra->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Compra eliminada.']);
    }

    /** Detalle: barra de ecuación, pagos, documento con watermark, retenciones, NC/ND (informe §2.4). */
    public function show(Compra $compra)
    {
        $CurrentPage = 'compras';
        $compra->load(['items', 'conceptos', 'proveedor.condicionIva', 'categoria', 'pagos.cuentaTesoreria', 'pagos.retenciones', 'notasCreditoDebito', 'remitos']);
        $cuentas = CuentaTesoreria::visibles()->orderBy('orden')->orderBy('nombre')->get();
        $depositos = Deposito::where('activo', true)->orderBy('nombre')->get();

        return view('compras.detalle', compact('CurrentPage', 'compra', 'cuentas', 'depositos'));
    }

    public function pdf(Compra $compra)
    {
        $compra->load(['items', 'conceptos', 'proveedor.condicionIva', 'categoria', 'comprobanteFiscal']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('compras.pdf', compact('compra'));

        return $pdf->stream('compra-'.$compra->nro_comprobante.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /** "Agregar Pago" (informe §2.4). */
    public function pagoStore(StorePagoRequest $request, Compra $compra): JsonResponse
    {
        $datos = $request->validated();
        $cuenta = CuentaTesoreria::findOrFail($datos['cuenta_tesoreria_id']);

        $pago = $this->pagos->registrarPago($compra, (float) $datos['monto'], $cuenta, Carbon::parse($datos['fecha']), $datos['nota'] ?? null);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Compra '.$compra->nro_comprobante.' actualizada con éxito.',
            'pago' => $pago,
            'pagado' => $compra->pagado(),
            'a_pagar' => $compra->aPagar(),
            'estado_pago' => $compra->estadoPago(),
        ], 201);
    }

    /** US3 (spec 039): Recibo de Pago — documento no fiscal, mejor esfuerzo (sin capturas de Contagram). */
    public function reciboPago(Compra $compra, Pago $pago)
    {
        if ($pago->compra_id !== $compra->id) {
            abort(404, 'El pago no pertenece a esta Compra.');
        }

        $pago->load('cuentaTesoreria');
        $compra->load('proveedor');
        $datosEmpresa = \App\Models\DatosEmpresa::instancia();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('recibos.pdf', [
            'numero' => $pago->id,
            'fecha' => $pago->fecha,
            'tipoContraparte' => 'Proveedor',
            'nombreContraparte' => optional($compra->proveedor)->nombre,
            'medio' => optional($pago->cuentaTesoreria)->nombre,
            'nota' => $pago->nota,
            'monto' => $pago->monto,
            'datosEmpresa' => $datosEmpresa,
        ]);

        return $pdf->stream('recibo-REC-'.$pago->id.'.pdf', ['Content-Disposition' => 'inline']);
    }

    public function pagoDestroy(Compra $compra, Pago $pago): JsonResponse
    {
        if ($pago->compra_id !== $compra->id) {
            abort(404);
        }

        $this->pagos->anularPago($pago);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Pago anulado.',
            'pagado' => $compra->pagado(),
            'a_pagar' => $compra->aPagar(),
            'estado_pago' => $compra->estadoPago(),
        ]);
    }

    /** "+ Agregar Retención" (US2, informe §2.5). */
    public function retencionStore(StoreRetencionRequest $request, Compra $compra): JsonResponse
    {
        $datos = $request->validated();

        $retencion = \App\Models\Retencion::create($datos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Retención creada correctamente.',
            'retencion' => $retencion,
        ], 201);
    }

    /** "Crear Remito" — encabezado mínimo (FR-011, mismo criterio que Ventas). */
    public function remitoStore(Request $request, Compra $compra): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'nullable|date']);

        $remito = $compra->remitos()->create([
            'fecha' => $datos['fecha'] ?? now()->local()->toDateString(),
            'nro_remito' => Remito::siguienteNumero(),
        ]);

        return response()->json(['ok' => true, 'mensaje' => 'Remito creado.', 'remito' => $remito], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function guardarItems(Compra $compra, array $items): void
    {
        foreach ($items as $item) {
            $compra->items()->create($item);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $conceptos
     */
    private function guardarConceptos(Compra $compra, array $conceptos): void
    {
        foreach ($conceptos as $concepto) {
            $compra->conceptos()->create($concepto);
        }
    }
}
