<?php

namespace Database\Seeders;

use App\Models\Permiso;
use Illuminate\Database\Seeder;

/** Catálogo granular de permisos modulo.accion cubriendo los módulos 001–013 (research D3). */
class PermisoSeeder extends Seeder
{
    public function run(): void
    {
        $catalogo = [
            'ventas' => [
                'ver' => 'Ver ventas',
                'crear' => 'Crear ventas',
                'editar' => 'Editar ventas',
                'eliminar' => 'Eliminar ventas',
                'facturar' => 'Emitir factura electrónica de una venta',
            ],
            'presupuestos' => [
                'ver' => 'Ver presupuestos',
                'crear' => 'Crear presupuestos',
                'editar' => 'Editar presupuestos',
                'eliminar' => 'Eliminar presupuestos',
            ],
            'abonos' => [
                'ver' => 'Ver abonos',
                'crear' => 'Crear abonos',
                'editar' => 'Editar abonos',
                'eliminar' => 'Eliminar abonos',
            ],
            'otros-ingresos' => [
                'ver' => 'Ver otros ingresos',
                'crear' => 'Crear otros ingresos',
                'editar' => 'Editar otros ingresos',
                'eliminar' => 'Eliminar otros ingresos',
            ],
            'compras' => [
                'ver' => 'Ver compras',
                'crear' => 'Crear compras',
                'editar' => 'Editar compras',
                'eliminar' => 'Eliminar compras',
            ],
            'gastos' => [
                'ver' => 'Ver gastos',
                'crear' => 'Crear gastos',
                'editar' => 'Editar gastos',
                'eliminar' => 'Eliminar gastos',
            ],
            'clientes' => [
                'ver' => 'Ver clientes',
                'crear' => 'Crear clientes',
                'editar' => 'Editar clientes',
                'eliminar' => 'Eliminar clientes',
            ],
            'proveedores' => [
                'ver' => 'Ver proveedores',
                'crear' => 'Crear proveedores',
                'editar' => 'Editar proveedores',
                'eliminar' => 'Eliminar proveedores',
            ],
            'productos' => [
                'ver' => 'Ver productos y servicios',
                'crear' => 'Crear productos y servicios',
                'editar' => 'Editar productos y servicios',
                'eliminar' => 'Eliminar productos y servicios',
            ],
            'tesoreria' => [
                'ver' => 'Ver cuentas y movimientos de tesorería',
                'crear' => 'Crear cuentas y movimientos de tesorería',
                'editar' => 'Editar cuentas y movimientos de tesorería',
                'eliminar' => 'Eliminar cuentas y movimientos de tesorería',
            ],
            'facturacion' => [
                'ver' => 'Ver Facturación Electrónica (puntos de venta, certificados, comprobantes)',
            ],
            'informes' => [
                'ver' => 'Ver Informes',
            ],
            'mensajeria' => [
                'ver' => 'Ver la bandeja de Mensajería de Mercado Libre',
                'responder' => 'Responder mensajes de Mercado Libre',
            ],
            'integraciones' => [
                'ver' => 'Ver Integraciones (MercadoLibre, TiendaNube)',
                'gestionar' => 'Conectar/desconectar canales y disparar sincronizaciones',
            ],
            'configuracion' => [
                'perfil' => 'Editar Mi Perfil / Mi Logo',
                'usuarios' => 'Administrar Usuarios',
                'roles' => 'Administrar Roles y Permisos',
                'funciones' => 'Administrar Funciones Avanzadas',
                'importar' => 'Importar Datos por Excel',
                'ajustes' => 'Administrar Ajustes',
            ],
        ];

        foreach ($catalogo as $modulo => $acciones) {
            foreach ($acciones as $accion => $descripcion) {
                Permiso::updateOrCreate(
                    ['codigo' => "{$modulo}.{$accion}"],
                    ['descripcion' => $descripcion, 'modulo' => $modulo]
                );
            }
        }
    }
}
