<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePresupuestoRequest;
use App\Http\Requests\UpdatePresupuestoRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\ConfiguracionVentas;
use App\Models\Etiqueta;
use App\Models\ListaPrecio;
use App\Models\Deposito;
use App\Models\Presupuesto;
use App\Models\Proveedor;
use App\Models\Provincia;
use App\Models\TipoProducto;
use App\Models\Vendedor;
use App\Models\Venta;
use App\Services\Ingresos\CalculoComprobante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/** Presupuestos (US1, MVP): listado + KPIs, formulario de página completa, documento imprimible. */
class PresupuestoController extends Controller
{
    public function __construct(private readonly CalculoComprobante $calculo)
    {
    }

    public function index(Request $request)
    {
        $CurrentPage = 'presupuestos';
        $kpis = $this->kpis($request);

        return view('presupuestos.index', [
            'CurrentPage' => $CurrentPage,
            'kpis' => $kpis,
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(['id', 'nombre']),
            'vendedores' => Vendedor::orderBy('nombre')->get(['id', 'nombre']),
            'etiquetas' => Etiqueta::orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Panel de Filtros de Presupuestos (informe §2.2 `[67]`, 15 campos). Formas de Pago y
     * Métodos de Envío son texto libre en el modelo (sin catálogo propio, igual que en Ventas).
     */
    private function queryFiltrada(Request $request): Builder
    {
        $query = Presupuesto::query()->with(['cliente:id,nombre', 'categoria:id,nombre']);

        return $this->aplicarFiltros($query, $request);
    }

    /** Filtros del listado, aplicados tanto sobre el listado (data()) como sobre los KPIs (kpis()). */
    private function aplicarFiltros(Builder $query, Request $request): Builder
    {
        if ($request->filled('id')) {
            $query->where('id', (int) $request->input('id'));
        }
        if ($request->filled('producto_id')) {
            $query->whereHas('items', fn (Builder $q) => $q->whereIn('producto_id', (array) $request->input('producto_id')));
        }
        if ($request->filled('cliente_id')) {
            $query->whereIn('cliente_id', (array) $request->input('cliente_id'));
        }
        if ($request->filled('estado')) {
            $estados = (array) $request->input('estado');
            $query->where(function (Builder $q) use ($estados) {
                foreach ($estados as $estado) {
                    $q->orWhere(fn (Builder $qq) => $estado === 'vencido'
                        ? $qq->where('estado', 'pendiente')->whereDate('fecha_validez', '<', now())
                        : $qq->where('estado', $estado));
                }
            });
        }
        if ($request->filled('categoria_id')) {
            $query->whereIn('categoria_id', (array) $request->input('categoria_id'));
        }
        if ($request->filled('buscar')) {
            $kw = $request->input('buscar');
            $query->where('nro_presupuesto', 'like', "%{$kw}%");
        }
        if ($request->filled('etiqueta_id')) {
            $query->whereHas('etiquetas', fn (Builder $q) => $q->whereIn('etiquetas.id', (array) $request->input('etiqueta_id')));
        }
        if ($request->filled('vendedor_id')) {
            $query->whereIn('vendedor_id', (array) $request->input('vendedor_id'));
        }
        if ($request->filled('formas_pago')) {
            $query->where('formas_pago', 'like', '%'.$request->input('formas_pago').'%');
        }
        if ($request->filled('metodos_envio')) {
            $query->where('metodos_envio', 'like', '%'.$request->input('metodos_envio').'%');
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
        if ($request->filled('servicio_desde')) {
            $query->whereDate('servicio_desde', '>=', $request->input('servicio_desde'));
        }
        if ($request->filled('servicio_hasta')) {
            $query->whereDate('servicio_hasta', '<=', $request->input('servicio_hasta'));
        }
        // Rangos de la barra superior (mismos que Ventas). Acá el "vencimiento" del Presupuesto
        // es su fecha de validez: no hay vencimiento de cobro porque todavía no es una Venta.
        if ($request->filled('emision_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->input('emision_desde'));
        }
        if ($request->filled('emision_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->input('emision_hasta'));
        }
        if ($request->filled('vencimiento_desde')) {
            $query->whereDate('fecha_validez', '>=', $request->input('vencimiento_desde'));
        }
        if ($request->filled('vencimiento_hasta')) {
            $query->whereDate('fecha_validez', '<=', $request->input('vencimiento_hasta'));
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
            ->addColumn('acciones', fn (Presupuesto $p) => view('presupuestos._row_actions', ['presupuesto' => $p])->render())
            ->addColumn('estado_visual', fn (Presupuesto $p) => $p->estado_visual)
            ->addColumn('cliente', fn (Presupuesto $p) => optional($p->cliente)->nombre)
            ->addColumn('categoria', fn (Presupuesto $p) => optional($p->categoria)->nombre)
            ->editColumn('created_at', fn (Presupuesto $p) => $p->created_at?->local()->format('d/m/Y H:i'))
            ->editColumn('fecha_emision', fn (Presupuesto $p) => optional($p->fecha_emision)->format('d/m/Y'))
            ->editColumn('fecha_validez', fn (Presupuesto $p) => optional($p->fecha_validez)->format('d/m/Y'))
            ->editColumn('subtotal_sin_descuento', fn (Presupuesto $p) => (float) $p->subtotal_sin_descuento)
            ->editColumn('descuento', fn (Presupuesto $p) => (float) $p->descuento)
            ->editColumn('subtotal_con_descuento', fn (Presupuesto $p) => (float) $p->subtotal_con_descuento)
            ->editColumn('total', fn (Presupuesto $p) => (float) $p->total)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** Barra de 5 KPIs sobre el listado (informe §2.1), recalculada contra los mismos filtros. */
    private function kpis(Request $request): array
    {
        $base = fn () => $this->aplicarFiltros(Presupuesto::query(), $request);

        return [
            'ventas' => $base()->whereNotNull('venta_id')->count(),
            'vencidos_rechazados' => $base()->where('estado', 'rechazado')
                ->orWhere(fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_validez', '<', now()))
                ->count(),
            'pendientes' => $base()->where('estado', 'pendiente')->count(),
            'aceptados' => $base()->where('estado', 'aceptado')->count(),
            'total_posibles' => (float) $base()->where('estado', 'pendiente')->sum('total'),
        ];
    }

    public function create(Request $request)
    {
        $CurrentPage = 'presupuestos';
        $submitToken = (string) \Illuminate\Support\Str::uuid();

        // Defaults de Configuración & Ajustes → Ventas (reutiliza Categoría/Vendedor/Lista de
        // Precios de Ventas + días de validez propios), mismo criterio que VentaController@create:
        // sólo alta nueva, y sólo si el registro configurado sigue existiendo y activo.
        $configuracionVentas = ConfiguracionVentas::first();
        $defaults = null;
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

            $defaults = [
                'categoriaId' => $categoriaDefault?->id,
                'vendedorId' => $vendedorDefault?->id,
                'listaPrecioId' => $listaPrecioDefault?->id,
                'fechaValidez' => $configuracionVentas->dias_validez_presupuesto !== null
                    ? now()->addDays($configuracionVentas->dias_validez_presupuesto)->format('Y-m-d')
                    : null,
            ];
        }

        return view('presupuestos.form', [
            'CurrentPage' => $CurrentPage,
            'presupuesto' => null,
            'submitToken' => $submitToken,
            'defaults' => $defaults,
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(),
            'listasPrecio' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(),
            'vendedores' => Vendedor::orderBy('nombre')->get(),
            // Para el modal completo de alta/edición de Cliente reutilizado desde el select (clientes._modal_form).
            'categorias' => Categoria::venta()->orderBy('nombre')->get(),
            'condicionesIva' => CondicionIva::orderBy('nombre')->get(),
            'provincias' => Provincia::orderBy('nombre')->pluck('nombre'),
            // Catálogos para los modales Ver/Editar de Producto reutilizados desde el
            // detalle del Presupuesto (spec 052).
            'tiposProducto' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'depositos' => Deposito::activos()->orderBy('nombre')->get(),
        ]);
    }

    public function store(StorePresupuestoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $vendedorId = $datos['vendedor_id'] ?? null;

        if (Presupuesto::where('submit_token', $datos['submit_token'])->exists()) {
            // Doble submit con el mismo token (SC-007): devolvemos el ya creado, sin duplicar.
            $existente = Presupuesto::where('submit_token', $datos['submit_token'])->first();

            return response()->json([
                'ok' => true,
                'mensaje' => 'Presupuesto creado con éxito.',
                'presupuesto' => $existente,
                'redirect' => route('presupuestos.show', $existente),
            ], 201);
        }

        $presupuesto = DB::transaction(function () use ($datos, $vendedorId) {
            $descuentoGeneralTipo = $datos['descuento_general_tipo'] ?? 'porcentaje';
            $descuentoGeneralValor = $descuentoGeneralTipo === 'monto'
                ? ($datos['descuento_general_monto'] ?? null)
                : ($datos['descuento_general_pct'] ?? null);
            $resultado = $this->calculo->calcular($datos['items'], $descuentoGeneralTipo, $descuentoGeneralValor, $datos['conceptos'] ?? []);

            $presupuesto = Presupuesto::create([
                'nro_presupuesto' => Presupuesto::siguienteNumero(),
                'cliente_id' => $datos['cliente_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'lista_precio_id' => $datos['lista_precio_id'] ?? null,
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'estado' => 'pendiente',
                'descuento_general_tipo' => $descuentoGeneralTipo,
                'descuento_general_pct' => $descuentoGeneralTipo === 'porcentaje' ? ($datos['descuento_general_pct'] ?? null) : null,
                'descuento_general_monto' => $descuentoGeneralTipo === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_cliente' => $datos['nota_cliente'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'formas_pago' => $datos['formas_pago'] ?? null,
                'metodos_envio' => $datos['metodos_envio'] ?? null,
                'vendedor_id' => $vendedorId,
                'creado_por_id' => auth()->id(),
                'submit_token' => $datos['submit_token'],
            ]);

            $this->guardarItems($presupuesto, $resultado['items']);
            $this->guardarConceptos($presupuesto, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($presupuesto, $datos['etiquetas'] ?? []);

            return $presupuesto;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Presupuesto '.$presupuesto->id.' creado con éxito.',
            'presupuesto' => $presupuesto,
            'redirect' => route('presupuestos.show', $presupuesto),
        ], 201);
    }

    public function edit(Presupuesto $presupuesto)
    {
        $CurrentPage = 'presupuestos';
        $presupuesto->load(['items', 'conceptos', 'etiquetas', 'cliente', 'categoria', 'listaPrecio', 'vendedor']);
        $categoriasVenta = Categoria::venta()->activas()->orderBy('nombre')->get();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();
        $categorias = Categoria::venta()->orderBy('nombre')->get();
        $condicionesIva = CondicionIva::orderBy('nombre')->get();
        $provincias = Provincia::orderBy('nombre')->pluck('nombre');
        $tiposProducto = TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $depositos = Deposito::activos()->orderBy('nombre')->get();

        return view('presupuestos.form', compact('CurrentPage', 'presupuesto', 'categoriasVenta', 'listasPrecio', 'vendedores', 'categorias', 'condicionesIva', 'provincias', 'tiposProducto', 'proveedores', 'depositos'));
    }

    public function update(UpdatePresupuestoRequest $request, Presupuesto $presupuesto): JsonResponse
    {
        if ($presupuesto->convertido()) {
            return response()->json(['ok' => false, 'mensaje' => 'El presupuesto ya fue convertido en venta.'], 422);
        }

        $datos = $request->validated();

        DB::transaction(function () use ($datos, $presupuesto) {
            $descuentoGeneralTipo = $datos['descuento_general_tipo'] ?? 'porcentaje';
            $descuentoGeneralValor = $descuentoGeneralTipo === 'monto'
                ? ($datos['descuento_general_monto'] ?? null)
                : ($datos['descuento_general_pct'] ?? null);
            $resultado = $this->calculo->calcular($datos['items'], $descuentoGeneralTipo, $descuentoGeneralValor, $datos['conceptos'] ?? []);

            $presupuesto->update([
                'cliente_id' => $datos['cliente_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'lista_precio_id' => $datos['lista_precio_id'] ?? null,
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
                'descuento_general_tipo' => $descuentoGeneralTipo,
                'descuento_general_pct' => $descuentoGeneralTipo === 'porcentaje' ? ($datos['descuento_general_pct'] ?? null) : null,
                'descuento_general_monto' => $descuentoGeneralTipo === 'monto' ? ($datos['descuento_general_monto'] ?? null) : null,
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

            $presupuesto->items()->delete();
            $presupuesto->conceptos()->delete();
            $this->guardarItems($presupuesto, $resultado['items']);
            $this->guardarConceptos($presupuesto, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($presupuesto, $datos['etiquetas'] ?? []);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Presupuesto '.$presupuesto->id.' actualizado con éxito.',
            'presupuesto' => $presupuesto->fresh(),
            // Igual que el alta: se vuelve a la ficha del presupuesto recién guardado. Sin este
            // `redirect` el JS caía al fallback `rutas.index` y editar terminaba en el listado,
            // obligando a buscar de nuevo el presupuesto para ver cómo quedó.
            'redirect' => route('presupuestos.show', $presupuesto),
        ]);
    }

    public function destroy(Presupuesto $presupuesto): JsonResponse
    {
        $presupuesto->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Presupuesto eliminado.']);
    }

    /** Cambio de estado directo desde el menú de fila (informe §2.3). */
    public function estado(Request $request, Presupuesto $presupuesto): JsonResponse
    {
        $datos = $request->validate(['estado' => 'required|in:pendiente,rechazado,aceptado']);
        $presupuesto->update(['estado' => $datos['estado']]);

        return response()->json(['ok' => true, 'estado' => $presupuesto->estado]);
    }

    /** "Ver" — documento imprimible como página (no modal), fiel al informe §2.4. */
    public function show(Presupuesto $presupuesto)
    {
        $CurrentPage = 'presupuestos';
        $presupuesto->load(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor', 'etiquetas']);
        $datosEmpresa = \App\Models\DatosEmpresa::instancia();

        return view('presupuestos.documento', compact('CurrentPage', 'presupuesto', 'datosEmpresa'));
    }

    public function pdf(Presupuesto $presupuesto)
    {
        $presupuesto->load(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor']);
        $datosEmpresa = \App\Models\DatosEmpresa::instancia();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('presupuestos.pdf', compact('presupuesto', 'datosEmpresa'));

        return $pdf->stream('presupuesto-'.$presupuesto->id.'.pdf', ['Content-Disposition' => 'inline']);
    }

    /** "Crear Venta": convierte el presupuesto (no reconvertible — FR-009) y redirige a Nueva Venta pre-cargada. */
    public function crearVenta(Presupuesto $presupuesto): JsonResponse
    {
        if ($presupuesto->convertido()) {
            return response()->json(['ok' => false, 'mensaje' => 'El presupuesto ya fue convertido en venta.'], 422);
        }

        return response()->json([
            'ok' => true,
            'redirect' => route('ventas.create', ['presupuesto' => $presupuesto->id]),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function guardarItems(Presupuesto $presupuesto, array $items): void
    {
        foreach ($items as $item) {
            $presupuesto->items()->create($item);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $conceptos
     */
    private function guardarConceptos(Presupuesto $presupuesto, array $conceptos): void
    {
        foreach ($conceptos as $concepto) {
            $presupuesto->conceptos()->create($concepto);
        }
    }

    /**
     * @param  array<int, string>  $nombres
     */
    private function sincronizarEtiquetas(Presupuesto $presupuesto, array $nombres): void
    {
        $ids = collect($nombres)
            ->filter()
            ->map(fn (string $nombre) => Etiqueta::firstOrCreate(['nombre' => trim($nombre)])->id)
            ->all();

        $presupuesto->etiquetas()->sync($ids);
    }
}
