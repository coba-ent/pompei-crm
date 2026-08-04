<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePresupuestoRequest;
use App\Http\Requests\UpdatePresupuestoRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Etiqueta;
use App\Models\ListaPrecio;
use App\Models\Presupuesto;
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

    public function index()
    {
        $CurrentPage = 'presupuestos';
        $kpis = $this->kpis();

        return view('presupuestos.index', compact('CurrentPage', 'kpis'));
    }

    private function queryFiltrada(Request $request): Builder
    {
        $query = Presupuesto::query()->with(['cliente:id,nombre', 'categoria:id,nombre']);

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->input('cliente_id'));
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }
        if ($request->filled('buscar')) {
            $kw = $request->input('buscar');
            $query->where('nro_presupuesto', 'like', "%{$kw}%");
        }

        return $query;
    }

    public function data(Request $request): JsonResponse
    {
        $query = $this->queryFiltrada($request);

        return DataTables::eloquent($query)
            ->addColumn('estado_badge', fn (Presupuesto $p) => view('presupuestos._estado_badge', ['presupuesto' => $p])->render())
            ->addColumn('acciones', fn (Presupuesto $p) => view('presupuestos._row_actions', ['presupuesto' => $p])->render())
            ->addColumn('estado_visual', fn (Presupuesto $p) => $p->estado_visual)
            ->addColumn('cliente', fn (Presupuesto $p) => optional($p->cliente)->nombre)
            ->addColumn('categoria', fn (Presupuesto $p) => optional($p->categoria)->nombre)
            ->editColumn('fecha_emision', fn (Presupuesto $p) => optional($p->fecha_emision)->format('d/m/Y'))
            ->editColumn('fecha_validez', fn (Presupuesto $p) => optional($p->fecha_validez)->format('d/m/Y'))
            ->editColumn('subtotal_sin_descuento', fn (Presupuesto $p) => (float) $p->subtotal_sin_descuento)
            ->editColumn('descuento', fn (Presupuesto $p) => (float) $p->descuento)
            ->editColumn('subtotal_con_descuento', fn (Presupuesto $p) => (float) $p->subtotal_con_descuento)
            ->editColumn('total', fn (Presupuesto $p) => (float) $p->total)
            ->rawColumns(['estado_badge', 'acciones'])
            ->toJson();
    }

    /** Barra de 5 KPIs sobre el listado (informe §2.1). */
    private function kpis(): array
    {
        return [
            'ventas' => Presupuesto::whereNotNull('venta_id')->count(),
            'vencidos_rechazados' => Presupuesto::where('estado', 'rechazado')
                ->orWhere(fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_validez', '<', now()))
                ->count(),
            'pendientes' => Presupuesto::where('estado', 'pendiente')->count(),
            'aceptados' => Presupuesto::where('estado', 'aceptado')->count(),
            'total_posibles' => (float) Presupuesto::where('estado', 'pendiente')->sum('total'),
        ];
    }

    public function create(Request $request)
    {
        $CurrentPage = 'presupuestos';
        $submitToken = (string) \Illuminate\Support\Str::uuid();

        return view('presupuestos.form', [
            'CurrentPage' => $CurrentPage,
            'presupuesto' => null,
            'submitToken' => $submitToken,
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(),
            'listasPrecio' => ListaPrecio::where('activo', true)->orderBy('nombre')->get(),
            'vendedores' => Vendedor::orderBy('nombre')->get(),
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
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);

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
                'descuento_general_pct' => $datos['descuento_general_pct'] ?? null,
                'subtotal_sin_descuento' => $resultado['subtotal_sin_descuento'],
                'descuento' => $resultado['descuento'],
                'subtotal_con_descuento' => $resultado['subtotal_con_descuento'],
                'total' => $resultado['total'],
                'nota_cliente' => $datos['nota_cliente'] ?? null,
                'nota_interna' => $datos['nota_interna'] ?? null,
                'formas_pago' => $datos['formas_pago'] ?? null,
                'metodos_envio' => $datos['metodos_envio'] ?? null,
                'vendedor_id' => $vendedorId,
                'submit_token' => $datos['submit_token'],
            ]);

            $this->guardarItems($presupuesto, $resultado['items']);
            $this->guardarConceptos($presupuesto, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($presupuesto, $datos['etiquetas'] ?? []);

            return $presupuesto;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Presupuesto '.$presupuesto->nro_presupuesto.' creado con éxito.',
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

        return view('presupuestos.form', compact('CurrentPage', 'presupuesto', 'categoriasVenta', 'listasPrecio', 'vendedores'));
    }

    public function update(UpdatePresupuestoRequest $request, Presupuesto $presupuesto): JsonResponse
    {
        if ($presupuesto->convertido()) {
            return response()->json(['ok' => false, 'mensaje' => 'El presupuesto ya fue convertido en venta.'], 422);
        }

        $datos = $request->validated();

        DB::transaction(function () use ($datos, $presupuesto) {
            $resultado = $this->calculo->calcular($datos['items'], $datos['descuento_general_pct'] ?? null, $datos['conceptos'] ?? []);

            $presupuesto->update([
                'cliente_id' => $datos['cliente_id'],
                'categoria_id' => $datos['categoria_id'] ?? null,
                'lista_precio_id' => $datos['lista_precio_id'] ?? null,
                'fecha_emision' => $datos['fecha_emision'],
                'fecha_validez' => $datos['fecha_validez'] ?? null,
                'servicio_desde' => $datos['servicio_desde'] ?? null,
                'servicio_hasta' => $datos['servicio_hasta'] ?? null,
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

            $presupuesto->items()->delete();
            $presupuesto->conceptos()->delete();
            $this->guardarItems($presupuesto, $resultado['items']);
            $this->guardarConceptos($presupuesto, $datos['conceptos'] ?? []);
            $this->sincronizarEtiquetas($presupuesto, $datos['etiquetas'] ?? []);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Presupuesto '.$presupuesto->nro_presupuesto.' actualizado con éxito.',
            'presupuesto' => $presupuesto->fresh(),
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

        return view('presupuestos.documento', compact('CurrentPage', 'presupuesto'));
    }

    public function pdf(Presupuesto $presupuesto)
    {
        $presupuesto->load(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('presupuestos.pdf', compact('presupuesto'));

        return $pdf->stream('presupuesto-'.$presupuesto->nro_presupuesto.'.pdf', ['Content-Disposition' => 'inline']);
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
