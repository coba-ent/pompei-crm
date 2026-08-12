<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGastoRequest;
use App\Http\Requests\UpdateGastoRequest;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Services\Egresos\Pagos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/** Gastos (US3): documento atómico por modal, sin ficha de detalle (informe §3.4). */
class GastoController extends Controller
{
    public function __construct(private readonly Pagos $pagos)
    {
    }

    public function index()
    {
        $CurrentPage = 'gastos';
        $categorias = Categoria::gasto()->activas()->orderBy('nombre')->get();
        $cuentas = CuentaTesoreria::visibles()->orderBy('nombre')->get();

        return view('gastos.index', compact('CurrentPage', 'categorias', 'cuentas'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = Gasto::query()->with(['categoria:id,nombre,categoria_padre_id', 'categoria.padre:id,nombre', 'cuentaTesoreria:id,nombre']);

        return DataTables::eloquent($query)
            ->addColumn('acciones', fn (Gasto $g) => view('gastos._row_actions', ['gasto' => $g])->render())
            ->addColumn('estado', fn (Gasto $g) => $g->estado())
            ->addColumn('categoria', fn (Gasto $g) => optional($g->categoria)->padre
                ? $g->categoria->padre->nombre.' → '.$g->categoria->nombre
                : optional($g->categoria)->nombre)
            ->addColumn('medio_de_pago', fn (Gasto $g) => optional($g->cuentaTesoreria)->nombre)
            ->addColumn('fecha_raw', fn (Gasto $g) => optional($g->fecha)->format('Y-m-d'))
            ->editColumn('fecha', fn (Gasto $g) => optional($g->fecha)->format('d/m/Y'))
            ->editColumn('monto', fn (Gasto $g) => (float) $g->monto)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    public function store(StoreGastoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $gasto = Gasto::create([
            'fecha' => $datos['fecha'],
            'monto' => $datos['monto'],
            'categoria_id' => $datos['categoria_id'],
            'cuenta_tesoreria_id' => $datos['pendiente'] ?? false ? null : ($datos['cuenta_tesoreria_id'] ?? null),
            'descripcion' => $datos['descripcion'] ?? null,
            'pendiente' => $datos['pendiente'] ?? false,
            'usuario_id' => $request->user()?->id,
        ]);

        $this->pagos->registrarGasto($gasto);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Gasto creado correctamente.',
            'gasto' => $gasto,
        ], 201);
    }

    public function update(UpdateGastoRequest $request, Gasto $gasto): JsonResponse
    {
        $datos = $request->validated();

        $gasto->update([
            'fecha' => $datos['fecha'],
            'monto' => $datos['monto'],
            'categoria_id' => $datos['categoria_id'],
            'cuenta_tesoreria_id' => $datos['pendiente'] ?? false ? null : ($datos['cuenta_tesoreria_id'] ?? $gasto->cuenta_tesoreria_id),
            'descripcion' => $datos['descripcion'] ?? null,
            'pendiente' => $datos['pendiente'] ?? false,
        ]);

        // Conciliar: si dejó de estar pendiente y todavía no tiene movimiento, se genera recién ahí.
        $this->pagos->conciliarGasto($gasto->fresh());

        return response()->json([
            'ok' => true,
            'mensaje' => 'Gasto actualizado correctamente.',
            'gasto' => $gasto->fresh(),
        ]);
    }

    public function destroy(Gasto $gasto): JsonResponse
    {
        $this->pagos->anularGasto($gasto);

        return response()->json(['ok' => true, 'mensaje' => 'Gasto eliminado.']);
    }
}
