<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompraRequest;
use App\Http\Requests\StorePagoRequest;
use App\Http\Requests\UpdatePagoRequest;
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
     *
     * ## Los cuatro importes cierran entre sí: `Pagado + A Pagar + Vencido = Total`
     *
     * Espejo de {@see VentaController::kpis()} — misma ecuación que muestra Contagram arriba de su
     * listado, adoptada el 16/08/2026 para que las dos pantallas se lean igual. Implica que
     * **Total es el neto** (`Σ (total + ND − NC)`, no `Σ total`) y que **A Pagar y Vencido son
     * excluyentes**: antes "A Pagar" era todo lo pendiente y "Vencido" un subconjunto suyo, así
     * que sumarlos contaba dos veces lo vencido.
     *
     * A diferencia de Ventas, acá no hace falta ningún default de vencimiento: las 2.392 compras
     * lo tienen cargado, y en 1.057 es distinto de la emisión —los plazos reales del proveedor—,
     * así que asumir "vence el día que se emite" sería falso.
     */
    private function kpis(Request $request): array
    {
        $pagado = 'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0)';
        $nc = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0)";
        $nd = "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0)";
        // Términos de crédito de proveedor (spec 072): la aplicación de saldo a favor es una
        // transferencia entre dos compras, así que baja el A Pagar del destino y lo devuelve al
        // origen. El neto NO los lleva: no cambia lo facturado por el proveedor.
        $credito = \App\Services\Ingresos\SqlCredito::terminos('compras');
        $aPagar = "(compras.total + {$nd} - {$nc} - {$pagado} {$credito})";
        $neto = "(compras.total + {$nd} - {$nc})";
        // "Hoy" como parámetro y no `CURDATE()`: esa función no existe fuera de MySQL y reventaba
        // el endpoint bajo SQLite, que es donde corren los tests. Además el corte pasa a ser el
        // día del negocio y no el del servidor, que está en UTC.
        $hoy = now()->setTimezone(config('app.display_timezone'))->toDateString();
        $estaVencida = "compras.fecha_vto_pago IS NOT NULL AND compras.fecha_vto_pago < ? AND {$aPagar} > 0.005";

        $r = $this->aplicarFiltros(Compra::query(), $request)
            ->selectRaw("
                COUNT(*) AS cantidad,
                COALESCE(SUM({$neto}), 0) AS total,
                COALESCE(SUM({$pagado}), 0) AS pagado,
                COALESCE(SUM(CASE WHEN {$estaVencida} THEN 0 ELSE {$aPagar} END), 0) AS a_pagar,
                COALESCE(SUM(CASE WHEN {$estaVencida} THEN {$aPagar} ELSE 0 END), 0) AS vencido
            ", [$hoy, $hoy])
            ->first();

        return [
            'cantidad' => (int) $r->cantidad,
            'pagado' => round((float) $r->pagado, 2),
            'a_pagar' => round((float) $r->a_pagar, 2),
            'vencido' => round((float) $r->vencido, 2),
            'total' => round((float) $r->total, 2),
        ];
    }

    /** Número que el comprobante tenía en Contagram: de `2021-FC-2140` devuelve `2140`. */
    private function numeroContagram(string $legacyId): string
    {
        $partes = explode('-', $legacyId);

        return end($partes);
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
            // Busca por el id del CRM **y** por el número que la compra tenía en Contagram: el
            // `legacy_id` es `{año}-{familia}-{Id}`, así que se ancla al final para que "2140"
            // encuentre `2021-FC-2140` y no cualquier cosa que contenga esos dígitos.
            $id = trim((string) $request->input('id'));
            $query->where(fn (Builder $q) => $q
                ->where('id', (int) $id)
                ->orWhere('legacy_id', 'like', '%-'.$id)
            );
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
            $credito = \App\Services\Ingresos\SqlCredito::terminos('compras');
            $aPagar = "(compras.total + {$nd} - {$nc} - {$pagado} {$credito})";
            $estados = (array) $request->input('estado_pago');
            $query->where(function (Builder $q) use ($estados, $pagado, $aPagar) {
                foreach ($estados as $estado) {
                    $q->orWhere(function (Builder $qq) use ($estado, $pagado, $aPagar) {
                        match ($estado) {
                            // Sin exigir pago > 0: una compra saldada íntegramente con una NC no
                            // tiene pagos, pero Compra::estadoPago() ya la muestra "Pagado" (mira el
                            // A Pagar). Con la condición anterior el badge decía Pagado y el filtro
                            // la devolvía en "A Pagar" — 5 compras por $860.320,82.
                            'pagado' => $qq->whereRaw("{$aPagar} <= 0.005"),
                            'parcial' => $qq->whereRaw("{$pagado} > 0")->whereRaw("{$aPagar} > 0.005"),
                            // Mismo criterio que la card KPI "Vencido": vto. pasado y todavía queda saldo.
                            'vencido' => $qq->whereNotNull('fecha_vto_pago')->whereDate('fecha_vto_pago', '<', now())->whereRaw("{$aPagar} > 0.005"),
                            default => $qq->whereRaw("{$pagado} <= 0")->whereRaw("{$aPagar} > 0.005"),
                        };
                    });
                }
            });
        }
        if ($request->filled('factura_buscar')) {
            $kw = $request->input('factura_buscar');
            // El Nro. de Factura del proveedor vive en `compras.nro_comprobante`; la tabla
            // `comprobantes_fiscales` sólo guarda los comprobantes que emitimos nosotros por ARCA
            // (Ventas) o el CAE que el proveedor declara a mano, que casi ninguna Compra tiene.
            $query->where(fn (Builder $q) => $q->where('tipo_comprobante', 'like', "%{$kw}%")
                ->orWhere('nro_comprobante', 'like', "%{$kw}%")
                ->orWhereHas('comprobanteFiscal', fn (Builder $qq) => $qq->where('numero', 'like', "%{$kw}%")));
        }
        if ($request->filled('etiqueta_id')) {
            $query->whereHas('etiquetas', fn (Builder $q) => $q->whereIn('etiquetas.id', (array) $request->input('etiqueta_id')));
        }
        if ($request->filled('facturado')) {
            $valores = (array) $request->input('facturado');
            $query->where(function (Builder $q) use ($valores) {
                foreach ($valores as $valor) {
                    $q->orWhere(fn (Builder $qq) => $valor === '1'
                        ? $qq->whereHas('comprobanteFiscal')
                        : $qq->whereDoesntHave('comprobanteFiscal'));
                }
            });
        }
        if ($request->filled('medio_pago_id')) {
            $query->whereHas('pagos', fn (Builder $q) => $q->whereIn('cuenta_tesoreria_id', (array) $request->input('medio_pago_id')));
        }
        if ($request->filled('usuario_id')) {
            $query->whereIn('creado_por_id', (array) $request->input('usuario_id'));
        }
        if ($request->filled('nota_interna')) {
            $query->where('nota_interna', 'like', '%'.$request->input('nota_interna').'%');
        }
        if ($request->filled('deposito_id')) {
            $query->whereIn('deposito_id', (array) $request->input('deposito_id'));
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
            // Las migradas muestran el número que tenían en Contagram junto al id del CRM: es el
            // dato por el que se las busca cuando llega un comprobante viejo en papel.
            ->editColumn('id', fn (Compra $c) => $c->legacy_id === null
                ? (string) $c->id
                : $c->id.' <span class="badge bg-light text-muted" title="Número en Contagram">'
                    .e($this->numeroContagram($c->legacy_id)).'</span>')
            ->editColumn('created_at', fn (Compra $c) => $c->created_at?->local()->format('d/m/Y H:i'))
            ->editColumn('fecha_emision', fn (Compra $c) => optional($c->fecha_emision)->format('d/m/Y'))
            ->editColumn('fecha_vto_pago', fn (Compra $c) => optional($c->fecha_vto_pago)->format('d/m/Y'))
            ->editColumn('subtotal_sin_descuento', fn (Compra $c) => (float) $c->subtotal_sin_descuento)
            ->editColumn('descuento', fn (Compra $c) => (float) $c->descuento)
            ->editColumn('subtotal_con_descuento', fn (Compra $c) => (float) $c->subtotal_con_descuento)
            ->editColumn('total', fn (Compra $c) => (float) $c->total)
            ->rawColumns(['acciones', 'id'])
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
            $descuentoGeneralTipo = $datos['descuento_general_tipo'] ?? 'porcentaje';
            $descuentoGeneralValor = $descuentoGeneralTipo === 'monto'
                ? ($datos['descuento_general_monto'] ?? null)
                : ($datos['descuento_general_pct'] ?? null);
            $resultado = $this->calculo->calcular($datos['items'], $descuentoGeneralTipo, $descuentoGeneralValor, $datos['conceptos'] ?? []);

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
                'descuento_general_tipo' => $descuentoGeneralTipo,
                'descuento_general_pct' => $descuentoGeneralTipo === 'porcentaje' ? ($datos['descuento_general_pct'] ?? null) : null,
                'descuento_general_monto' => $descuentoGeneralTipo === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
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
            $descuentoGeneralTipo = $datos['descuento_general_tipo'] ?? 'porcentaje';
            $descuentoGeneralValor = $descuentoGeneralTipo === 'monto'
                ? ($datos['descuento_general_monto'] ?? null)
                : ($datos['descuento_general_pct'] ?? null);
            $resultado = $this->calculo->calcular($datos['items'], $descuentoGeneralTipo, $descuentoGeneralValor, $datos['conceptos'] ?? []);
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
                'descuento_general_tipo' => $descuentoGeneralTipo,
                'descuento_general_pct' => $descuentoGeneralTipo === 'porcentaje' ? ($datos['descuento_general_pct'] ?? null) : null,
                'descuento_general_monto' => $descuentoGeneralTipo === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
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
        $compra->load(['items', 'conceptos', 'proveedor.condicionIva', 'categoria', 'pagos.cuentaTesoreria', 'pagos.retenciones', 'comprobanteFiscal', 'notasCreditoDebito.comprobanteFiscal', 'notasCreditoDebito.notaAjustada.comprobanteFiscal', 'remitos.transportista', 'remitos.items', 'creditosRecibidos.origen', 'creditosRecibidos.notaCreditoDebito']);
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

    /**
     * Datos que necesita el modal de Pago para abrirse desde el menú de fila del listado, sin
     * pasar por la ficha. Se calculan en el momento (no se toman de la fila ya renderizada) para
     * que el "A Pagar" contemple las NC/ND y los pagos registrados mientras la tabla estaba abierta.
     */
    public function pagoContexto(Compra $compra): JsonResponse
    {
        return response()->json([
            'id' => $compra->id,
            'nroComprobante' => $compra->nro_comprobante,
            'total' => (float) $compra->total,
            'aPagar' => $compra->aPagar(),
            'cuentas' => CuentaTesoreria::visibles()->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
        ]);
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

    public function pagoUpdate(UpdatePagoRequest $request, Compra $compra, Pago $pago): JsonResponse
    {
        if ($pago->compra_id !== $compra->id || $pago->trashed()) {
            abort(404);
        }

        $datos = $request->validated();
        $cuenta = CuentaTesoreria::findOrFail($datos['cuenta_tesoreria_id']);

        try {
            $pago = $this->pagos->actualizarPago($pago, (float) $datos['monto'], $cuenta, Carbon::parse($datos['fecha']), $datos['nota'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'errors' => ['monto' => [$e->getMessage()]]], 422);
        }

        $pago->load('cuentaTesoreria');

        return response()->json([
            'ok' => true,
            'mensaje' => 'Pago actualizado.',
            'pago' => $pago,
            'pagado' => $compra->pagado(),
            'a_pagar' => $compra->aPagar(),
            'estado_pago' => $compra->estadoPago(),
        ]);
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
