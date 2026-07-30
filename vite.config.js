import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/clientes.js', 'resources/js/productos.js', 'resources/js/proveedores.js', 'resources/js/informe-stock.js', 'resources/js/configuracion-usuarios.js', 'resources/js/configuracion-roles.js', 'resources/js/configuracion-depositos.js', 'resources/js/tesoreria.js', 'resources/js/presupuestos.js', 'resources/js/ventas.js', 'resources/js/otros-ingresos.js', 'resources/js/compras.js', 'resources/js/gastos.js', 'resources/js/dashboard.js', 'resources/js/funciones-avanzadas.js', 'resources/js/mercadolibre.js', 'resources/js/mercadolibre-ventas.js', 'resources/js/mercadolibre-vinculaciones.js', 'resources/js/tiendanube.js', 'resources/js/tiendanube-ventas.js', 'resources/js/tiendanube-vinculaciones.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
