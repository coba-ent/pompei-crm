<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOtroIngresoRequest;
use App\Http\Requests\UpdateOtroIngresoRequest;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Services\Ingresos\Cobranzas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/** Otros Ingresos (US3): el módulo más simple de los tres (informe §4). */
class OtroIngresoController extends Controller
{
    public function __construct(private readonly Cobranzas $cobranzas)
    {
    }

    public function index()
    {
        $CurrentPage = 'otros-ingresos';
        $categorias = Categoria::deIngreso()->activas()->orderBy('nombre')->get();
        // Un ingreso entra a una caja, un banco o una cuenta a cobrar.
        $cuentas = CuentaTesoreria::visibles()->paraCobrar()->ordenadas()->get();
        // Para el filtro "Usuario" del panel (informe_contagram_ingresos.md 4.2).
        $usuarios = \App\Models\User::orderBy('name')->get(['id', 'name']);

        return view('otros-ingresos.index', compact('CurrentPage', 'categorias', 'cuentas', 'usuarios'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = OtroIngreso::query()->with(['categoria:id,nombre', 'cuentaTesoreria:id,nombre']);

        // Panel de filtros de Contagram (informe_contagram_ingresos.md 4.2): Id, Categoria,
        // Medio de Cobro, Estado del Cobro, Descripcion y Usuario, mas el rango de Emision
        // del header. Multi-select como en el resto de los listados, asi que aceptan array.
        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }
        if ($request->filled('categoria_id')) {
            $query->whereIn('categoria_id', (array) $request->input('categoria_id'));
        }
        if ($request->filled('cuenta_tesoreria_id')) {
            $query->whereIn('cuenta_tesoreria_id', (array) $request->input('cuenta_tesoreria_id'));
        }
        if ($request->filled('estado_cobro')) {
            $estados = (array) $request->input('estado_cobro');
            // `pendiente` es el booleano que decide el estado (OtroIngreso::estado()); con las dos
            // opciones tildadas no se filtra nada, que es lo mismo que no elegir ninguna.
            $query->where(function (Builder $q) use ($estados) {
                foreach ($estados as $estado) {
                    $q->orWhere('pendiente', $estado === 'pendiente');
                }
            });
        }
        if ($request->filled('descripcion')) {
            $query->where('descripcion', 'like', '%'.$request->input('descripcion').'%');
        }
        if ($request->filled('usuario_id')) {
            $query->whereIn('usuario_id', (array) $request->input('usuario_id'));
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->input('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->input('fecha_hasta'));
        }

        return DataTables::eloquent($query)
            ->addColumn('acciones', fn (OtroIngreso $o) => view('otros-ingresos._row_actions', ['otroIngreso' => $o])->render())
            ->addColumn('estado', fn (OtroIngreso $o) => $o->estado())
            ->addColumn('categoria', fn (OtroIngreso $o) => optional($o->categoria)->nombre)
            ->addColumn('medio_de_cobro', fn (OtroIngreso $o) => optional($o->cuentaTesoreria)->nombre)
            ->addColumn('fecha_raw', fn (OtroIngreso $o) => optional($o->fecha)->format('Y-m-d'))
            ->editColumn('fecha', fn (OtroIngreso $o) => optional($o->fecha)->format('d/m/Y'))
            ->editColumn('monto', fn (OtroIngreso $o) => (float) $o->monto)
            ->rawColumns(['acciones'])
            ->toJson();
    }

    public function store(StoreOtroIngresoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $otroIngreso = DB::transaction(function () use ($datos, $request) {
            $otroIngreso = OtroIngreso::create([
                'fecha' => $datos['fecha'],
                'monto' => $datos['monto'],
                'categoria_id' => $datos['categoria_id'],
                'cuenta_tesoreria_id' => $datos['pendiente'] ?? false ? null : ($datos['cuenta_tesoreria_id'] ?? null),
                'descripcion' => $datos['descripcion'] ?? null,
                'pendiente' => $datos['pendiente'] ?? false,
                'usuario_id' => $request->user()?->id,
            ]);

            $this->cobranzas->registrarOtroIngreso($otroIngreso);

            return $otroIngreso;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Ingreso creado correctamente.',
            'otroIngreso' => $otroIngreso,
        ], 201);
    }

    public function update(UpdateOtroIngresoRequest $request, OtroIngreso $otroIngreso): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $otroIngreso) {
            $otroIngreso->update([
                'fecha' => $datos['fecha'],
                'monto' => $datos['monto'],
                'categoria_id' => $datos['categoria_id'],
                'cuenta_tesoreria_id' => $datos['pendiente'] ?? false ? null : ($datos['cuenta_tesoreria_id'] ?? $otroIngreso->cuenta_tesoreria_id),
                'descripcion' => $datos['descripcion'] ?? null,
                'pendiente' => $datos['pendiente'] ?? false,
            ]);

            // Conciliar: si dejó de estar pendiente y todavía no tiene movimiento, se genera recién ahí.
            $this->cobranzas->conciliar($otroIngreso->fresh());
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Ingreso actualizado correctamente.',
            'otroIngreso' => $otroIngreso->fresh(),
        ]);
    }

    public function destroy(OtroIngreso $otroIngreso): JsonResponse
    {
        $this->cobranzas->anularOtroIngreso($otroIngreso);

        return response()->json(['ok' => true, 'mensaje' => 'Ingreso eliminado.']);
    }
}
