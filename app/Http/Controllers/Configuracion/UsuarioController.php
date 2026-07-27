<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioStoreRequest;
use App\Http\Requests\UsuarioUpdateRequest;
use App\Models\Rol;
use App\Models\User;
use App\Services\GuardiaAdministrador;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Yajra\DataTables\Facades\DataTables;

/** ABM de usuarios internos (US2, spec 013): baja lógica, asignación de roles, guardia de administrador. */
class UsuarioController extends Controller
{
    public function __construct(private GuardiaAdministrador $guardiaAdministrador) {}

    public function index()
    {
        $CurrentPage = 'configuracion-usuarios';
        $roles = Rol::orderBy('nombre')->get();

        return view('configuracion.usuarios.index', compact('CurrentPage', 'roles'));
    }

    public function data(): JsonResponse
    {
        $query = User::query()->with('roles');

        return DataTables::eloquent($query)
            ->addColumn('roles', fn (User $u) => $u->roles->pluck('nombre')->join(', '))
            ->addColumn('acciones', fn (User $u) => view('configuracion.usuarios._row_actions', ['usuario' => $u])->render())
            ->rawColumns(['acciones'])
            ->toJson();
    }

    public function store(UsuarioStoreRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $roles = $datos['roles'] ?? [];
        unset($datos['roles']);

        $datos['password'] = Hash::make($datos['password']);

        $usuario = User::create($datos);
        $usuario->roles()->sync($roles);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Usuario creado correctamente.',
            'usuario' => $usuario->load('roles'),
        ]);
    }

    public function show(User $usuario): JsonResponse
    {
        return response()->json(['usuario' => $usuario->load('roles:id,nombre')]);
    }

    public function update(UsuarioUpdateRequest $request, User $usuario): JsonResponse
    {
        $datos = $request->validated();
        $roles = $datos['roles'] ?? [];
        unset($datos['roles']);

        if (empty($datos['password'])) {
            unset($datos['password']);
        } else {
            $datos['password'] = Hash::make($datos['password']);
        }

        // Guardia admin (D9): no permitir quitar el rol Admin al último administrador activo.
        $perderiaAdmin = $usuario->esAdmin() && ! in_array($this->rolAdminId(), $roles);
        if ($perderiaAdmin && $this->guardiaAdministrador->esUltimoAdminActivo($usuario)) {
            return response()->json([
                'ok' => false,
                'errors' => ['roles' => ['No se puede quitar el rol Admin al único administrador activo del sistema.']],
            ], 422);
        }

        $usuario->update($datos);
        $usuario->roles()->sync($roles);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Usuario actualizado correctamente.',
            'usuario' => $usuario->load('roles'),
        ]);
    }

    /** Alta/baja lógica (FR-013). Aplica la guardia de administrador (D9). */
    public function estado(User $usuario): JsonResponse
    {
        if ($usuario->activo && $this->guardiaAdministrador->esUltimoAdminActivo($usuario)) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No se puede dar de baja al único administrador activo del sistema.',
            ], 422);
        }

        $usuario->activo = ! $usuario->activo;
        $usuario->save();

        return response()->json([
            'ok' => true,
            'activo' => $usuario->activo,
            'mensaje' => $usuario->activo ? 'Usuario reactivado.' : 'Usuario inactivado.',
        ]);
    }

    private function rolAdminId(): ?int
    {
        return Rol::where('nombre', 'Admin')->value('id');
    }
}
