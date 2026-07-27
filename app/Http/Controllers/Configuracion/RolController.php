<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolStoreRequest;
use App\Http\Requests\RolUpdateRequest;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Yajra\DataTables\Facades\DataTables;

/** ABM de roles y su matriz de permisos (US2, spec 013). */
class RolController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-roles';

        return view('configuracion.roles.index', compact('CurrentPage'));
    }

    public function data(): JsonResponse
    {
        $query = Rol::query()->withCount(['permisos', 'usuarios']);

        return DataTables::eloquent($query)
            ->addColumn('permisos_count', fn (Rol $r) => $r->permisos_count)
            ->addColumn('usuarios_count', fn (Rol $r) => $r->usuarios_count)
            ->addColumn('acciones', fn (Rol $r) => view('configuracion.roles._row_actions', ['rol' => $r])->render())
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /** Catálogo de permisos agrupado por módulo, para la matriz del modal. */
    public function permisos(): JsonResponse
    {
        $modulos = Permiso::orderBy('modulo')->orderBy('codigo')->get()
            ->groupBy('modulo')
            ->map(fn ($permisos, $modulo) => [
                'modulo' => $modulo,
                'permisos' => $permisos->map(fn (Permiso $p) => [
                    'codigo' => $p->codigo,
                    'descripcion' => $p->descripcion,
                ])->values(),
            ])
            ->values();

        return response()->json(['modulos' => $modulos]);
    }

    public function store(RolStoreRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $codigosPermisos = $datos['permisos'] ?? [];
        unset($datos['permisos']);

        $rol = Rol::create($datos);
        $rol->permisos()->sync(Permiso::whereIn('codigo', $codigosPermisos)->pluck('id'));

        return response()->json(['ok' => true, 'mensaje' => 'Rol creado correctamente.', 'rol' => $rol->load('permisos')]);
    }

    public function show(Rol $rol): JsonResponse
    {
        return response()->json([
            'rol' => $rol->load('permisos:id,codigo'),
        ]);
    }

    public function update(RolUpdateRequest $request, Rol $rol): JsonResponse
    {
        $datos = $request->validated();
        $codigosPermisos = $datos['permisos'] ?? [];
        unset($datos['permisos']);

        $rol->update($datos);
        $rol->permisos()->sync(Permiso::whereIn('codigo', $codigosPermisos)->pluck('id'));

        return response()->json(['ok' => true, 'mensaje' => 'Rol actualizado correctamente.', 'rol' => $rol->load('permisos')]);
    }

    /** Rechaza si tiene usuarios asignados o es un rol de sistema (FR-010a). */
    public function destroy(Rol $rol): JsonResponse
    {
        if ($rol->es_sistema) {
            return response()->json(['ok' => false, 'mensaje' => 'No se puede eliminar un rol de sistema.'], 422);
        }

        if ($rol->usuarios()->exists()) {
            return response()->json(['ok' => false, 'mensaje' => 'No se puede eliminar un rol con usuarios asignados.'], 422);
        }

        $rol->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Rol eliminado.']);
    }
}
