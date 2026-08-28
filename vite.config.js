import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/clientes.js', 'resources/js/cliente-modal.js','resources/js/productos.js', 'resources/js/producto-modales.js', 'resources/js/proveedores.js', 'resources/js/informe-stock.js', 'resources/js/informe-cuenta-corriente.js', 'resources/js/configuracion-usuarios.js', 'resources/js/configuracion-roles.js', 'resources/js/configuracion-depositos.js', 'resources/js/tesoreria.js', 'resources/js/presupuestos.js', 'resources/js/ventas.js', 'resources/js/otros-ingresos.js', 'resources/js/compras.js', 'resources/js/notas-credito-debito.js', 'resources/js/remitos.js', 'resources/js/gastos.js', 'resources/js/dashboard.js', 'resources/js/funciones-avanzadas.js', 'resources/js/mercadolibre.js', 'resources/js/mercadolibre-ventas.js', 'resources/js/mercadolibre-vinculaciones.js', 'resources/js/tiendanube.js', 'resources/js/tiendanube-ventas.js', 'resources/js/tiendanube-vinculaciones.js', 'resources/js/mensajeria.js', 'resources/js/mi-perfil.js', 'resources/js/configuracion-tabs.js', 'resources/js/configuracion-ventas.js', 'resources/js/auditoria.js', 'resources/js/rango-emision.js', 'resources/js/fecha-ar.js', 'resources/js/buscador-catalogo.js', 'resources/js/errores-validacion.js', 'resources/js/informe-compras.js', 'resources/js/informe-gastos.js', 'resources/js/informe-cuenta-corriente-proveedores.js', 'resources/js/informe-ventas.js', 'resources/js/reporte-final.js', 'resources/js/informes-pivot.js', 'resources/js/informes-pivot-pantalla.js', 'resources/js/monitoreo.js', 'resources/js/monitoreo-topbar.js', 'resources/js/informe-contador.js', 'resources/js/envio-contador.js', 'resources/js/auth-password.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
