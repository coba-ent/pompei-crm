<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Rol::updateOrCreate(
            ['nombre' => 'Admin'],
            ['descripcion' => 'Acceso total al sistema.', 'es_sistema' => true]
        );
        $admin->permisos()->sync(Permiso::pluck('id'));

        $vendedor = Rol::updateOrCreate(
            ['nombre' => 'Vendedor'],
            ['descripcion' => 'Ventas, presupuestos y consulta de clientes/productos.', 'es_sistema' => false]
        );
        $vendedor->permisos()->sync(
            Permiso::whereIn('codigo', [
                'ventas.ver', 'ventas.crear', 'ventas.editar',
                'presupuestos.ver', 'presupuestos.crear', 'presupuestos.editar',
                'clientes.ver', 'clientes.crear', 'clientes.editar',
                'productos.ver',
            ])->pluck('id')
        );

        $contable = Rol::updateOrCreate(
            ['nombre' => 'Contable'],
            ['descripcion' => 'Compras, gastos, tesorería e informes.', 'es_sistema' => false]
        );
        $contable->permisos()->sync(
            Permiso::whereIn('codigo', [
                'compras.ver', 'compras.crear', 'compras.editar',
                'gastos.ver', 'gastos.crear', 'gastos.editar',
                'tesoreria.ver', 'tesoreria.crear', 'tesoreria.editar',
                'proveedores.ver', 'proveedores.crear', 'proveedores.editar',
                // Informes de los módulos que este rol ya administra (spec 090). No recibe Ventas,
                // Stock, Cta Cte Clientes ni Reporte Final: exceden su función. Una instalación
                // limpia tiene que dar el mismo estado que la migración sobre la base existente.
                'informes.compras', 'informes.gastos', 'informes.cuenta-corriente-proveedores',
                'informes.contador', 'informes.exportar',
            ])->pluck('id')
        );
    }
}
