<?php

namespace Tests\Concerns;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;

/**
 * spec 070: el Dashboard ahora filtra por permiso, y el `TestCase` base autentica por defecto un
 * usuario SIN roles (ver `tests/TestCase.php`). Los tests que ya existían antes de esa feature y
 * quieren seguir probando cálculo/neteo/estado vacío — no el filtrado en sí — necesitan un usuario
 * con los 7 permisos `.ver` relevantes del Dashboard, para que ninguna clave se omita por permiso.
 */
trait ActuaComoUsuarioConPermisos
{
    private const CODIGOS_PERMISOS_DASHBOARD = [
        'ventas.ver', 'otros-ingresos.ver', 'compras.ver', 'gastos.ver',
        'clientes.ver', 'productos.ver', 'tesoreria.ver',
    ];

    protected function actingAsUsuarioConTodosLosPermisosDashboard(): User
    {
        return $this->actingAsUsuarioConPermisos(self::CODIGOS_PERMISOS_DASHBOARD);
    }

    /** @param string[] $codigos códigos de permiso a asignarle al usuario, p. ej. ['ventas.ver'] */
    protected function actingAsUsuarioConPermisos(array $codigos): User
    {
        $permisos = collect($codigos)
            ->map(fn (string $codigo) => Permiso::firstOrCreate(['codigo' => $codigo], [
                'descripcion' => $codigo, 'modulo' => explode('.', $codigo)[0],
            ]));

        $rol = Rol::create(['nombre' => 'Rol de Prueba '.uniqid()]);
        $rol->permisos()->sync($permisos->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->sync([$rol->id]);

        $this->actingAs($user);

        return $user;
    }
}
