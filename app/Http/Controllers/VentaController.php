<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCobroRequest;
use App\Http\Requests\UpdateCobroRequest;
use App\Http\Requests\StoreVentaRequest;
use App\Http\Requests\UpdateVentaRequest;
use App\Models\Categoria;
use App\Models\Cobro;
use App\Models\CondicionIva;
use App\Models\ConfiguracionVentas;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Etiqueta;
use App\Models\ListaPrecio;
use App\Models\Presupuesto;
use App\Models\Proveedor;
use App\Models\Provincia;
use App\Models\Remito;
use App\Models\TipoProducto;
use App\Models\Vendedor;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Arca\EmisorComprobante;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\Excepciones\ArcaRechazoException;
use App\Services\Arca\Excepciones\CertificadoNoConfiguradoException;
use App\Services\Ingresos\CalculoComprobante;
use App\Services\Ingresos\Cobranzas;
use App\Services\Ingresos\StockDeVenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/** Ventas (US2): listado, formulario de página completa, detalle con cobranza. */
class VentaController extends Controller
{
    public function __construct(
        private readonly CalculoComprobante $calculo,
        private readonly Cobranzas $cobranzas,
        private readonly StockDeVenta $stockDeVenta,
        private readonly EmisorComprobante $emisorComprobante,
    ) {
    }

    public function index(Request $request)
    {
        $CurrentPage = 'ventas';

        return view('ventas.index', [
            'CurrentPage' => $CurrentPage,
            'kpis' => $this->kpis($request),
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(['id', 'nombre']),
            'vendedores' => Vendedor::orderBy('nombre')->get(['id', 'nombre']),
            'etiquetas' => Etiqueta::orderBy('nombre')->get(['id', 'nombre']),
            // paraCobrar(): en una cobranza sólo tienen sentido las cuentas donde entra plata.
            'cuentasTesoreria' => CuentaTesoreria::visibles()->paraCobrar()->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
            'depositos' => Deposito::activos()->orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Barra de 5 KPIs del listado, espejo de Compras (documentacion_principal_crm.md §4.1):
     * Cantidad, Cobrado, A Cobrar, Vencido, Total.
     *
     * Se calcula con **una sola consulta agregada**. La versión anterior hacía `Venta::all()` y
     * recorría la colección llamando `aCobrar()` por fila, que a su vez dispara 3 consultas
     * (cobros + NC + ND). Con las 138 ventas de prueba ni se notaba; con el histórico de Contagram
     * cargado son ~24.000 modelos hidratados y ~70.000 consultas **en cada carga de la pantalla**,
     * que la dejaba en más de un minuto (medido el 10/08/2026 al importar).
     *
     * Los subselects mantienen la definición exacta de los métodos del modelo:
     * `A Cobrar = Total + Σ ND − Σ NC − Cobrado`, y "vencido" es el A Cobrar > 0 de las ventas con
     * `fecha_vto_cobro` pasada. Si cambia esa fórmula en Venta, hay que reflejarla acá.
     */
    private function kpis(Request $request): array
    {
        // JOIN contra subconsultas ya agrupadas, no subselects correlacionados: éstos se evalúan
        // una vez por fila (1,5s con 23.659 ventas), mientras que así cada tabla se recorre una
        // sola vez y el motor une por clave.
        $cobrado = 'COALESCE(c.monto, 0)';
        $nc = 'COALESCE(n.credito, 0)';
        $nd = 'COALESCE(n.debito, 0)';
        $aCobrar = "(ventas.total + {$nd} - {$nc} - {$cobrado})";

        $r = $this->aplicarFiltros(Venta::query(), $request)
            ->leftJoinSub(
                DB::table('cobros')->selectRaw('venta_id, SUM(monto) AS monto')
                    ->whereNull('deleted_at')->groupBy('venta_id'),
                'c', 'c.venta_id', '=', 'ventas.id'
            )
            ->leftJoinSub(
                DB::table('notas_credito_debito')->selectRaw("
                        venta_id,
                        SUM(CASE WHEN tipo = 'credito' THEN monto ELSE 0 END) AS credito,
                        SUM(CASE WHEN tipo = 'debito'  THEN monto ELSE 0 END) AS debito
                    ")->whereNull('deleted_at')->whereNotNull('venta_id')->groupBy('venta_id'),
                'n', 'n.venta_id', '=', 'ventas.id'
            )
            ->selectRaw("
                COUNT(*) AS cantidad,
                COALESCE(SUM(ventas.total), 0) AS total,
                COALESCE(SUM({$cobrado}), 0) AS cobrado,
                COALESCE(SUM({$aCobrar}), 0) AS a_cobrar,
                COALESCE(SUM(CASE
                    WHEN ventas.fecha_vto_cobro IS NOT NULL
                     AND ventas.fecha_vto_cobro < CURDATE()
                     AND {$aCobrar} > 0.005
                    THEN {$aCobrar} ELSE 0 END), 0) AS vencido
            ")
            ->first();

        return [
            'cantidad' => (int) $r->cantidad,
            'cobrado' => round((float) $r->cobrado, 2),
            'a_cobrar' => round((float) $r->a_cobrar, 2),
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

    /**
     * Panel de Filtros de Ventas (informe §3.5 `[90]` + captura real 06/08/2026 con 20 campos).
     * Transportista no tiene tabla/columna propia en el CRM — se omite (brecha documentada en
     * documentacion_principal_crm.md §5).
     */
    private function queryFiltrada(Request $request): Builder
    {
        $query = Venta::query()->with(['cliente:id,nombre', 'categoria:id,nombre', 'presupuesto:id', 'listaPrecio:id,nombre', 'vendedor:id,nombre', 'etiquetas:id,nombre', 'cobros.cuentaTesoreria:id,nombre', 'comprobanteFiscal:id,comprobantable_type,comprobantable_id,estado', 'items.producto:id,nombre']);

        return $this->aplicarFiltros($query, $request);
    }

    /** Filtros del listado, aplicados tanto sobre el listado (data()) como sobre los KPIs (kpis()). */
    private function aplicarFiltros(Builder $query, Request $request): Builder
    {
        if ($request->filled('id')) {
            // Busca por el id del CRM **y** por el número que la venta tenía en Contagram: los
            // comprobantes en papel y los reclamos de clientes traen el número viejo, que es el
            // único dato con el que se puede encontrar una venta de 2021 años después.
            // `legacy_id` es `{año}-{familia}-{Id}`, así que se ancla al final para que "2140"
            // encuentre `2021-FC-2140` y no cualquier cosa que contenga esos dígitos.
            $id = trim((string) $request->input('id'));
            $query->where(fn (Builder $q) => $q
                ->where('id', (int) $id)
                ->orWhere('legacy_id', 'like', '%-'.$id)
            );
        }
        if ($request->filled('cliente_id')) {
            $query->whereIn('cliente_id', (array) $request->input('cliente_id'));
        }
        if ($request->filled('buscar')) {
            $kw = $request->input('buscar');
            $query->where('nro_comprobante', 'like', "%{$kw}%");
        }
        if ($request->filled('creada_desde')) {
            // FR-035a (spec 017): "Creada Desde" agrega Tiendanube como cuarto valor,
            // junto a MercadoLibre / Presupuesto / Venta directa — sin filtro separado.
            match ($request->input('creada_desde')) {
                'mercadolibre' => $query->where('origen', 'mercadolibre'),
                'tiendanube' => $query->where('origen', 'tiendanube'),
                'presupuesto' => $query->whereNotNull('presupuesto_id'),
                default => $query->whereNull('presupuesto_id')->whereNotIn('origen', ['mercadolibre', 'tiendanube']),
            };
        }
        if ($request->filled('estado_cobro')) {
            match ($request->input('estado_cobro')) {
                'sin_cobrar' => $query->whereDoesntHave('cobros'),
                'cobrada' => $query->whereHas('cobros')->whereRaw('(select coalesce(sum(monto),0) from cobros where cobros.venta_id = ventas.id) >= ventas.total'),
                'parcial' => $query->whereHas('cobros')->whereRaw('(select coalesce(sum(monto),0) from cobros where cobros.venta_id = ventas.id) < ventas.total'),
                // Mismo criterio que la card KPI "Vencido": vto. pasado y todavía queda saldo
                // (A Cobrar real, con NC/ND — no sólo cobros vs. total como los casos de arriba).
                'vencido' => $query->whereNotNull('fecha_vto_cobro')->whereDate('fecha_vto_cobro', '<', now())->whereRaw("(ventas.total
                        + COALESCE((SELECT SUM(monto) FROM notas_credito_debito WHERE venta_id = ventas.id AND tipo = 'debito' AND deleted_at IS NULL), 0)
                        - COALESCE((SELECT SUM(monto) FROM notas_credito_debito WHERE venta_id = ventas.id AND tipo = 'credito' AND deleted_at IS NULL), 0)
                        - COALESCE((SELECT SUM(monto) FROM cobros WHERE venta_id = ventas.id AND deleted_at IS NULL), 0)
                    ) > 0.005"),
                default => null,
            };
        }
        if ($request->filled('categoria_id')) {
            $query->whereIn('categoria_id', (array) $request->input('categoria_id'));
        }
        if ($request->filled('estado_factura')) {
            match ($request->input('estado_factura')) {
                'sin_emitir' => $query->whereDoesntHave('comprobanteFiscal'),
                default => $query->whereHas('comprobanteFiscal', fn (Builder $q) => $q->where('estado', $request->input('estado_factura'))),
            };
        }
        if ($request->filled('factura_buscar')) {
            $kw = $request->input('factura_buscar');
            $query->where(function (Builder $q) use ($kw) {
                $q->where('tipo_comprobante', 'like', "%{$kw}%")
                    ->orWhereHas('comprobanteFiscal', fn (Builder $qq) => $qq->where('numero', 'like', "%{$kw}%"));
            });
        }
        if ($request->filled('etiqueta_id')) {
            $query->whereHas('etiquetas', fn (Builder $q) => $q->whereIn('etiquetas.id', (array) $request->input('etiqueta_id')));
        }
        if ($request->filled('vendedor_id')) {
            $query->whereIn('vendedor_id', (array) $request->input('vendedor_id'));
        }
        if ($request->filled('remitos')) {
            $request->input('remitos') === '1' ? $query->whereHas('remitos') : $query->whereDoesntHave('remitos');
        }
        if ($request->filled('remito_buscar')) {
            $query->whereHas('remitos', fn (Builder $q) => $q->where('nro_remito', 'like', '%'.$request->input('remito_buscar').'%'));
        }
        if ($request->filled('deposito_id')) {
            $query->whereHas('movimientosStock', fn (Builder $q) => $q->where('deposito_id', $request->input('deposito_id')));
        }
        if ($request->filled('medio_cobro_id')) {
            $query->whereHas('cobros', fn (Builder $q) => $q->where('cuenta_tesoreria_id', $request->input('medio_cobro_id')));
        }
        if ($request->filled('usuario_id')) {
            $query->whereIn('creado_por_id', (array) $request->input('usuario_id'));
        }
        if ($request->filled('nota_cliente')) {
            $query->where('nota_cliente', 'like', '%'.$request->input('nota_cliente').'%');
        }
        if ($request->filled('nota_interna')) {
            $query->where('nota_interna', 'like', '%'.$request->input('nota_interna').'%');
        }
        if ($request->filled('emision_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->input('emision_desde'));
        }
        if ($request->filled('emision_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->input('emision_hasta'));
        }
        if ($request->filled('vencimiento_desde')) {
            $query->whereDate('fecha_vto_cobro', '>=', $request->input('vencimiento_desde'));
        }
        if ($request->filled('vencimiento_hasta')) {
            $query->whereDate('fecha_vto_cobro', '<=', $request->input('vencimiento_hasta'));
        }
        if ($request->filled('servicio_desde')) {
            $query->whereDate('servicio_desde', '>=', $request->input('servicio_desde'));
        }
        if ($request->filled('servicio_hasta')) {
            $query->whereDate('servicio_hasta', '<=', $request->input('servicio_hasta'));
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
        $query = $this->queryFiltrada($request);

        return DataTables::eloquent($query)
            ->addColumn('acciones', fn (Venta $v) => view('ventas._row_actions', ['venta' => $v])->render())
            ->addColumn('estado_cobro', fn (Venta $v) => $v->estadoCobro())
            ->addColumn('creada_desde', fn (Venta $v) => match (true) {
                $v->origen === 'mercadolibre' => 'MercadoLibre',
                $v->origen === 'tiendanube' => 'Tiendanube',
                (bool) $v->presupuesto_id => 'Presupuesto '.$v->presupuesto_id,
                default => 'Venta',
            })
            ->addColumn('cliente', fn (Venta $v) => optional($v->cliente)->nombre)
            // Las migradas muestran el número que tenían en Contagram junto al id del CRM: es el
            // dato por el que se las busca cuando llega un comprobante viejo en papel.
            ->editColumn('id', fn (Venta $v) => $v->legacy_id === null
                ? (string) $v->id
                : $v->id.' <span class="badge bg-light text-muted" title="Número en Contagram">'
                    .e($this->numeroContagram($v->legacy_id)).'</span>')
            ->addColumn('productos', fn (Venta $v) => $v->items->map(fn (VentaItem $i) => $i->producto?->nombre ?? $i->descripcion)->filter()->implode(', '))
            ->addColumn('categoria', fn (Venta $v) => optional($v->categoria)->nombre)
            ->addColumn('cobrado', fn (Venta $v) => $v->cobrado())
            ->addColumn('a_cobrar', fn (Venta $v) => $v->aCobrar())
            ->addColumn('medio_de_cobro', fn (Venta $v) => optional($v->cobros->last()?->cuentaTesoreria)->nombre)
            ->addColumn('etiquetas', fn (Venta $v) => $v->etiquetas->pluck('nombre')->implode(', '))
            ->addColumn('lista_precio', fn (Venta $v) => optional($v->listaPrecio)->nombre)
            ->addColumn('vendedor', fn (Venta $v) => optional($v->vendedor)->nombre)
            ->editColumn('created_at', fn (Venta $v) => $v->created_at?->local()->format('d/m/Y H:i'))
            ->editColumn('fecha_emision', fn (Venta $v) => optional($v->fecha_emision)->format('d/m/Y'))
            ->editColumn('fecha_validez', fn (Venta $v) => optional($v->fecha_validez)->format('d/m/Y'))
            ->editColumn('subtotal_sin_descuento', fn (Venta $v) => (float) $v->subtotal_sin_descuento)
            ->editColumn('descuento', fn (Venta $v) => (float) $v->descuento)
            ->editColumn('subtotal_con_descuento', fn (Venta $v) => (float) $v->subtotal_con_descuento)
            ->editColumn('total', fn (Venta $v) => (float) $v->total)
            ->rawColumns(['acciones', 'id'])
            ->toJson();
    }

    public function create(Request $request)
    {
        $CurrentPage = 'ventas';
        $submitToken = (string) \Illuminate\Support\Str::uuid();
        $presupuesto = null;

        if ($request->filled('presupuesto')) {
            $presupuesto = Presupuesto::with(['items', 'conceptos', 'etiquetas', 'cliente'])->find($request->input('presupuesto'));
            if ($presupuesto?->convertido()) {
                $presupuesto = null;
            }
        }

        // Defaults de Configuración & Ajustes → Ventas (spec 043, FR-010/FR-012/FR-013): sólo
        // aplican en alta nueva (no edición, no conversión desde Presupuesto), y sólo si el
        // registro referenciado sigue existiendo y activo en su catálogo.
        $defaults = null;
        if (! $presupuesto) {
            $configuracionVentas = ConfiguracionVentas::first();
            if ($configuracionVentas) {
                $categoriaDefault = $configuracionVentas->categoria_id
                    ? Categoria::venta()->activas()->find($configuracionVentas->categoria_id)
                    : null;
                $vendedorDefault = $configuracionVentas->vendedor_id
                    ? Vendedor::find($configuracionVentas->vendedor_id)
                    : null;
                $listaPrecioDefault = $configuracionVentas->lista_precio_id
                    ? ListaPrecio::where('activo', true)->find($configuracionVentas->lista_precio_id)
                    : null;
                $depositoDefault = $configuracionVentas->deposito_id
                    ? Deposito::activos()->find($configuracionVentas->deposito_id)
                    : null;

                $defaults = [
                    'categoriaId' => $categoriaDefault?->id,
                    'vendedorId' => $vendedorDefault?->id,
                    'listaPrecioId' => $listaPrecioDefault?->id,
                    'depositoId' => $depositoDefault?->id,
                    'tipoComprobante' => $configuracionVentas->tipo_comprobante,
                    'fechaVtoCobro' => $configuracionVentas->dias_vto_cobro !== null
                        ? now()->addDays($configuracionVentas->dias_vto_cobro)->format('Y-m-d')
                        : null,
                ];
            }
        }

        return view('ventas.form', [
            'CurrentPage' => $CurrentPage,
            'venta' => null,
            'presupuestoOrigen' => $presupuesto,
            'submitToken' => $submitToken,
            'defaults' => $defaults,
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(),
            'listasPrecio' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(),
            'vendedores' => Vendedor::orderBy('nombre')->get(),
            'depositos' => Deposito::activos()->orderBy('nombre')->get(),
            // Para el modal completo de alta/edición de Cliente reutilizado desde el select (clientes._modal_form).
            'categorias' => Categoria::venta()->orderBy('nombre')->get(),
            'condicionesIva' => CondicionIva::orderBy('nombre')->get(),
            'provincias' => Provincia::orderBy('nombre')->pluck('nombre'),
            // Catálogos para los modales Ver/Editar de Producto reutilizados desde el
            // detalle de la Venta (spec 052).
            'tiposProducto' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(StoreVentaRequest $request): JsonResponse
    {
        $datos = $request->validated();

        if (Venta::withTrashed()->where('submit_token', $datos['submit_token'])->exists()) {
            $existente = Venta::withTrashed()->where('submit_token', $datos['submit_token'])->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'Venta '.$existente->nro_comprobante.' creada con éxito.',
                'venta' => $existente,
                'redirect' => route('ventas.show', $existente),
            ], 201);
        }

        $venta = DB::transaction(function () use ($datos, $request) {
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);

            $venta = Venta::create([
                'presupuesto_id' => $datos['presupuesto_id'] ?? null,
                'cliente_id' => $datos['cliente_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'lista_precio_id' => $datos['lista_precio_id'] ?? null,
                'deposito_id' => $datos['deposito_id'],
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'tipo_comprobante' => $datos['tipo_comprobante'],
                'nro_comprobante' => Venta::siguienteNroComprobante($datos['tipo_comprobante']),
                'fecha_vto_cobro' => $datos['fecha_vto_cobro'] ?? null,
                'descuento_general_pct' => $datos['descuento_general_pct'] ?? null,
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_cliente' => $datos['nota_cliente'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'formas_pago' => $datos['formas_pago'] ?? null,
                'metodos_envio' => $datos['metodos_envio'] ?? null,
                'vendedor_id' => $datos['vendedor_id'] ?? null,
                'creado_por_id' => auth()->id(),
                'submit_token' => $datos['submit_token'],
            ]);

            $this->guardarItems($venta, $resultado['items']);
            $this->guardarConceptos($venta, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($venta, $datos['etiquetas'] ?? []);

            $this->stockDeVenta->aplicarAlta($venta->load('items.producto'));

            if (! empty($datos['presupuesto_id'])) {
                $presupuesto = Presupuesto::find($datos['presupuesto_id']);
                if ($presupuesto && ! $presupuesto->convertido()) {
                    $presupuesto->update(['venta_id' => $venta->id]);
                }
            }

            return $venta;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Venta '.$venta->nro_comprobante.' creada con éxito.',
            'venta' => $venta,
            'redirect' => route('ventas.show', $venta),
        ], 201);
    }

    public function edit(Venta $venta)
    {
        $CurrentPage = 'ventas';
        $venta->load(['items', 'conceptos', 'etiquetas', 'cliente', 'categoria', 'listaPrecio', 'vendedor', 'deposito']);
        $categoriasVenta = Categoria::venta()->activas()->orderBy('nombre')->get();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();
        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $categorias = Categoria::venta()->orderBy('nombre')->get();
        $condicionesIva = CondicionIva::orderBy('nombre')->get();
        $provincias = Provincia::orderBy('nombre')->pluck('nombre');

        $presupuestoOrigen = null;
        $tiposProducto = TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        return view('ventas.form', compact('CurrentPage', 'venta', 'categoriasVenta', 'listasPrecio', 'vendedores', 'depositos', 'presupuestoOrigen', 'categorias', 'condicionesIva', 'provincias', 'tiposProducto', 'proveedores'));
    }

    public function update(UpdateVentaRequest $request, Venta $venta): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $venta) {
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);
            $itemsAnteriores = $venta->items()->with('producto')->get();
            $depositoAnteriorId = $venta->deposito_id;

            $venta->update([
                'cliente_id' => $datos['cliente_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'lista_precio_id' => $datos['lista_precio_id'] ?? null,
                'deposito_id' => $datos['deposito_id'],
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'tipo_comprobante' => $datos['tipo_comprobante'],
                'fecha_vto_cobro' => $datos['fecha_vto_cobro'] ?? null,
                'descuento_general_pct' => $datos['descuento_general_pct'] ?? null,
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_cliente' => $datos['nota_cliente'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'formas_pago' => $datos['formas_pago'] ?? null,
                'metodos_envio' => $datos['metodos_envio'] ?? null,
                'vendedor_id' => $datos['vendedor_id'] ?? null,
            ]);

            $venta->items()->delete();
            $venta->conceptos()->delete();
            $this->guardarItems($venta, $resultado['items']);
            $this->guardarConceptos($venta, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($venta, $datos['etiquetas'] ?? []);

            $depositoAnterior = $depositoAnteriorId ? \App\Models\Deposito::find($depositoAnteriorId) : null;
            $this->stockDeVenta->reaplicarPorEdicion($venta->load('items.producto'), $itemsAnteriores, $depositoAnterior);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Venta '.$venta->nro_comprobante.' actualizada con éxito.',
            'venta' => $venta->fresh(),
        ]);
    }

    public function destroy(Venta $venta): JsonResponse
    {
        $venta->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Venta eliminada.']);
    }

    /** Detalle: barra de ecuación, cobranzas, documento con watermark, NC/ND (informe §3.8). */
    public function show(Venta $venta)
    {
        $CurrentPage = 'ventas';
        $venta->load(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor', 'etiquetas', 'cobros.cuentaTesoreria', 'notasCreditoDebito', 'remitos', 'mlOrden']);
        $cuentas = CuentaTesoreria::visibles()->paraCobrar()->orderBy('orden')->orderBy('nombre')->get();
        $depositos = Deposito::activos()->orderBy('nombre')->get();

        return view('ventas.detalle', compact('CurrentPage', 'venta', 'cuentas', 'depositos'));
    }

    public function pdf(Venta $venta)
    {
        $venta->load(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor', 'comprobanteFiscal.puntoVenta', 'cobros.cuentaTesoreria']);
        $datosEmpresa = \App\Models\DatosEmpresa::instancia();

        $qrDataUri = null;
        if ($url = $venta->comprobanteFiscal?->urlQrAfip()) {
            $qrDataUri = (new \Endroid\QrCode\Builder\Builder())
                ->build(data: $url, size: 150)
                ->getDataUri();
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('ventas.pdf', compact('venta', 'qrDataUri', 'datosEmpresa'));

        return $pdf->stream('venta-'.$venta->nro_comprobante.'.pdf', ['Content-Disposition' => 'inline']);
    }

    public function ticket(Venta $venta)
    {
        $venta->load(['items', 'cliente']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('ventas.ticket', compact('venta'))->setPaper([0, 0, 226.77, 800]);

        return $pdf->stream('ticket-'.$venta->nro_comprobante.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /**
     * "Cobrar" / "Agregar Cobranza" (informe §3.2). Spec 040: ya NO dispara la emisión de CAE — el
     * envío a ARCA es una acción manual explícita del usuario (ver enviarArca()), a raíz del
     * incidente del 04/08/2026 (envío real no deseado a ARCA producción por un trigger automático).
     */
    public function cobranzaStore(StoreCobroRequest $request, Venta $venta): JsonResponse
    {
        $datos = $request->validated();
        $cuenta = CuentaTesoreria::findOrFail($datos['cuenta_tesoreria_id']);

        $cobro = $this->cobranzas->registrarCobro($venta, (float) $datos['monto'], $cuenta, Carbon::parse($datos['fecha']), $datos['nota'] ?? null);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Venta '.$venta->nro_comprobante.' actualizada con éxito.',
            'cobro' => $cobro,
            'cobrado' => $venta->cobrado(),
            'a_cobrar' => $venta->aCobrar(),
            'estado_cobro' => $venta->estadoCobro(),
        ], 201);
    }

    /**
     * Spec 040: envío manual a ARCA desde el listado de Ventas (reemplaza el trigger automático
     * eliminado de cobranzaStore()). Precondiciones explícitas (FR-003/FR-008/FR-012) antes de
     * intentar el envío real — a diferencia del fallback silencioso que tenía sentido para el
     * trigger automático, acá el usuario decidió a propósito enviar esta Venta y necesita una
     * respuesta visible en cualquier caso (contracts/enviar-arca.md).
     */
    public function enviarArca(Venta $venta): JsonResponse
    {
        if (! $venta->puedeEnviarseAArca()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esta Venta no puede enviarse a ARCA (tipo de comprobante no fiscal, ya tiene CAE aprobado, o la Facturación Electrónica está desactivada).',
            ], 422);
        }

        if (! \App\Models\CertificadoFiscal::activo()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No hay un certificado fiscal configurado — cargalo en Configuración & Ajustes → Facturación Electrónica antes de enviar.',
            ], 422);
        }

        $venta->load(['cliente.condicionIva', 'items']);

        $datos = [
            'tipo_comprobante' => $venta->tipo_comprobante,
            'fecha' => $venta->fecha_emision,
            'cliente' => [
                'cuit' => $venta->cliente?->cuit,
                'dni' => $venta->cliente?->tipo_documento === 'DNI' ? $venta->cliente?->cuit : null,
                'condicion_iva_codigo' => $venta->cliente?->condicionIva?->codigo_afip,
            ],
            'neto' => (float) $venta->subtotal_con_descuento,
            'iva' => round((float) $venta->total - (float) $venta->subtotal_con_descuento, 2),
            'total' => (float) $venta->total,
            'items' => $venta->items->map(fn ($item) => [
                'neto' => (float) $item->subtotal,
                'iva_pct' => (float) $item->iva_pct,
            ])->all(),
        ];

        try {
            $this->emisorComprobante->emitir($venta, $datos);

            $comprobante = $venta->comprobanteFiscal()->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'CAE obtenido correctamente.',
                'comprobante_fiscal' => $comprobante,
                'puede_enviarse_arca' => $venta->refresh()->puedeEnviarseAArca(),
            ]);
        } catch (CertificadoNoConfiguradoException) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No hay un certificado fiscal configurado — cargalo en Configuración & Ajustes → Facturación Electrónica antes de enviar.',
            ], 422);
        } catch (ArcaRechazoException|ArcaNoDisponibleException $e) {
            return response()->json([
                'ok' => false,
                'mensaje' => $e->getMessage(),
                'puede_enviarse_arca' => true,
            ]);
        }
    }

    /** US3 (spec 039): Recibo de Cobranza — documento no fiscal, mejor esfuerzo (sin capturas de Contagram). */
    public function reciboCobranza(Venta $venta, Cobro $cobro)
    {
        if ($cobro->venta_id !== $venta->id) {
            abort(404, 'La cobranza no pertenece a esta Venta.');
        }

        $cobro->load('cuentaTesoreria');
        $venta->load('cliente');
        $datosEmpresa = \App\Models\DatosEmpresa::instancia();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('recibos.pdf', [
            'numero' => $cobro->id,
            'fecha' => $cobro->fecha,
            'tipoContraparte' => 'Cliente',
            'nombreContraparte' => optional($venta->cliente)->nombre,
            'medio' => optional($cobro->cuentaTesoreria)->nombre,
            'nota' => $cobro->nota,
            'monto' => $cobro->monto,
            'datosEmpresa' => $datosEmpresa,
        ]);

        return $pdf->stream('recibo-REC-'.$cobro->id.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /** Editar cobranza (spec 053): monto/fecha/cuenta/nota, sin anular+recrear el movimiento. */
    public function cobranzaUpdate(UpdateCobroRequest $request, Venta $venta, Cobro $cobro): JsonResponse
    {
        if ($cobro->venta_id !== $venta->id || $cobro->trashed()) {
            abort(404);
        }

        $datos = $request->validated();
        $cuenta = CuentaTesoreria::findOrFail($datos['cuenta_tesoreria_id']);

        try {
            $cobro = $this->cobranzas->actualizarCobro($cobro, (float) $datos['monto'], $cuenta, Carbon::parse($datos['fecha']), $datos['nota'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'errors' => ['monto' => [$e->getMessage()]]], 422);
        }

        $cobro->load('cuentaTesoreria');

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cobranza actualizada.',
            'cobro' => $cobro,
            'cobrado' => $venta->cobrado(),
            'a_cobrar' => $venta->aCobrar(),
            'estado_cobro' => $venta->estadoCobro(),
        ]);
    }

    public function cobranzaDestroy(Venta $venta, Cobro $cobro): JsonResponse
    {
        if ($cobro->venta_id !== $venta->id) {
            abort(404);
        }

        $this->cobranzas->anularCobro($cobro);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cobranza anulada.',
            'cobrado' => $venta->cobrado(),
            'a_cobrar' => $venta->aCobrar(),
            'estado_cobro' => $venta->estadoCobro(),
        ]);
    }

    /** "Crear Remito" — encabezado mínimo (FR-018). */
    public function remitoStore(Request $request, Venta $venta): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'nullable|date']);

        $remito = $venta->remitos()->create([
            'fecha' => $datos['fecha'] ?? now()->local()->toDateString(),
            'nro_remito' => Remito::siguienteNumero(),
        ]);

        return response()->json(['ok' => true, 'mensaje' => 'Remito creado.', 'remito' => $remito], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function guardarItems(Venta $venta, array $items): void
    {
        foreach ($items as $item) {
            $venta->items()->create($item);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $conceptos
     */
    private function guardarConceptos(Venta $venta, array $conceptos): void
    {
        foreach ($conceptos as $concepto) {
            $venta->conceptos()->create($concepto);
        }
    }

    /**
     * @param  array<int, string>  $nombres
     */
    private function sincronizarEtiquetas(Venta $venta, array $nombres): void
    {
        $ids = collect($nombres)
            ->filter()
            ->map(fn (string $nombre) => Etiqueta::firstOrCreate(['nombre' => trim($nombre)])->id)
            ->all();

        $venta->etiquetas()->sync($ids);
    }
}
