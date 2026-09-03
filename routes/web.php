<?php

use App\Http\Controllers\AplicacionCreditoController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\Configuracion\ConfiguracionController;
use App\Http\Controllers\Configuracion\ConfiguracionVentasController;
use App\Http\Controllers\Configuracion\FuncionAvanzadaController;
use App\Http\Controllers\Configuracion\RolController;
use App\Http\Controllers\Configuracion\UsuarioController;
use App\Http\Controllers\CuentaTesoreriaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositoController;
use App\Http\Controllers\FacturacionElectronicaController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\Informes\CuentaCorrienteController;
use App\Http\Controllers\Informes\CuentaCorrienteProveedorController;
use App\Http\Controllers\Informes\EnvioContadorController;
use App\Http\Controllers\Informes\InformeComprasController;
use App\Http\Controllers\Informes\InformeContadorController;
use App\Http\Controllers\Informes\InformeGastosController;
use App\Http\Controllers\Informes\InformeStockController;
use App\Http\Controllers\Informes\InformeVentasController;
use App\Http\Controllers\Informes\InformeVistaController;
use App\Http\Controllers\Informes\ReporteFinalController;
use App\Http\Controllers\Ingresos\MercadoLibreVentaController;
use App\Http\Controllers\Ingresos\MercadoLibreVinculacionController;
use App\Http\Controllers\Ingresos\TiendanubeVentaController;
use App\Http\Controllers\Ingresos\TiendanubeVinculacionController;
use App\Http\Controllers\Integraciones\MercadoLibreBotConfiguracionController;
use App\Http\Controllers\Integraciones\MercadoLibreConfiguracionController;
use App\Http\Controllers\Integraciones\MercadoLibreMensajeriaWebhookController;
use App\Http\Controllers\Integraciones\MercadoLibreOAuthController;
use App\Http\Controllers\Integraciones\MercadoLibreRetencionPrecioController;
use App\Http\Controllers\Integraciones\TiendanubeConexionRestController;
use App\Http\Controllers\Integraciones\TiendanubeConfiguracionController;
use App\Http\Controllers\Integraciones\TiendanubeWebhookController;
use App\Http\Controllers\ListaPrecioController;
use App\Http\Controllers\Mensajeria\ConversacionController;
use App\Http\Controllers\Mensajeria\SugerenciaController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Controllers\Monitoreo\MonitoreoController;
use App\Http\Controllers\Monitoreo\MonitoreoResumenController;
use App\Http\Controllers\NotaCreditoDebitoController;
use App\Http\Controllers\OtroIngresoController;
use App\Http\Controllers\PresupuestoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\RemitoController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TesoreriaController;
use App\Http\Controllers\TipoProductoController;
use App\Http\Controllers\TransportistaController;
use App\Http\Controllers\VendedorController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

// Webhooks obligatorios de privacidad de la Application de Tiendanube (partner
// portal) — Tiendanube los llama sin sesión ni cookie, por eso van fuera del
// grupo `auth` y están exceptuados de CSRF (ver bootstrap/app.php).
Route::prefix('webhooks/tiendanube')->name('webhooks.tiendanube.')->group(function () {
    Route::post('store-redact', [TiendanubeWebhookController::class, 'storeRedact'])->name('storeRedact');
    Route::post('customers-redact', [TiendanubeWebhookController::class, 'customersRedact'])->name('customersRedact');
    Route::post('customers-data-request', [TiendanubeWebhookController::class, 'customersDataRequest'])->name('customersDataRequest');
});

// Webhook de notificaciones de Mercado Libre (spec 032) — Preguntas y Mensajería
// post-venta, mismo patrón que webhooks/tiendanube/* (sin sesión ni CSRF).
Route::post('webhooks/mercadolibre', [MercadoLibreMensajeriaWebhookController::class, 'recibir'])->name('webhooks.mercadolibre');

// Toda la app requiere sesión iniciada (spec 013 — controla el acceso al sistema completo).
Route::middleware('auth')->group(function () {

    Route::redirect('/', '/dashboard');

    // Inicio (spec 010) — dashboard de aterrizaje, sin middleware `permiso:` (visible para
    // cualquier usuario autenticado, igual criterio que antes tenía la ruta raíz `home`).
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::get('kpis', [DashboardController::class, 'kpis'])->name('kpis');
        Route::get('totales', [DashboardController::class, 'totales'])->name('totales');
        Route::get('grafico-mensual', [DashboardController::class, 'graficoMensual'])->name('grafico-mensual');
        Route::get('donas', [DashboardController::class, 'donas'])->name('donas');
        Route::get('rankings', [DashboardController::class, 'rankings'])->name('rankings');
    });

    // Mensajería de Mercado Libre (spec 032) — bandeja unificada de Preguntas y
    // Mensajería post-venta, respuesta manual con auditoría.
    Route::prefix('mensajeria')->name('mensajeria.')->group(function () {
        Route::middleware('permiso:mensajeria.ver')->group(function () {
            Route::get('/', [ConversacionController::class, 'index'])->name('index');
            Route::get('datatable', [ConversacionController::class, 'datatable'])->name('datatable');
            Route::get('actualizaciones', [ConversacionController::class, 'actualizaciones'])->name('actualizaciones');
            Route::get('{conversacion}', [ConversacionController::class, 'show'])->name('show');
            Route::post('{conversacion}/sugerencia', [SugerenciaController::class, 'store'])->name('sugerencia');
        });
        Route::middleware('permiso:mensajeria.responder')->group(function () {
            Route::post('{conversacion}/responder', [ConversacionController::class, 'responder'])->name('responder');
        });
    });

    // Base de Datos → Clientes
    Route::get('clientes/data', [ClienteController::class, 'data'])->name('clientes.data');
    Route::get('clientes/stats', [ClienteController::class, 'stats'])->name('clientes.stats');
    Route::get('clientes/export', [ClienteController::class, 'export'])->name('clientes.export');
    Route::patch('clientes/{cliente}/estado', [ClienteController::class, 'estado'])->name('clientes.estado');
    Route::get('clientes/opciones', [ClienteController::class, 'opciones'])->name('clientes.opciones');
    Route::get('clientes/verificar-documento', [ClienteController::class, 'verificarDocumento'])->name('clientes.verificar-documento');
    Route::get('geo/localidades', [GeoController::class, 'localidades'])->name('geo.localidades');
    Route::resource('clientes', ClienteController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // Base de Datos → Productos & Servicios
    Route::get('productos/data', [ProductoController::class, 'data'])->name('productos.data');
    Route::get('productos/stats', [ProductoController::class, 'stats'])->name('productos.stats');
    Route::get('productos/export', [ProductoController::class, 'export'])->name('productos.export');
    Route::patch('productos/{producto}/estado', [ProductoController::class, 'estado'])->name('productos.estado');
    Route::get('productos/opciones', [ProductoController::class, 'opciones'])->name('productos.opciones');
    Route::post('productos/{producto}/copia', [ProductoController::class, 'copia'])->name('productos.copia');
    Route::post('productos/acciones-masivas', [ProductoController::class, 'accionesMasivas'])->name('productos.acciones-masivas');
    // Botón "Sincronizar precios ahora" (spec 016) — reutiliza MercadoLibreVentaController,
    // vive en la pantalla de Productos (no en Mercado Libre): sin gate `permiso:ventas.ver`,
    // alcanza con poder llegar a la pantalla de Productos (mismo criterio que el resto de sus rutas).
    Route::post('productos/sincronizar-precios-ml', [MercadoLibreVentaController::class, 'sincronizarPrecios'])->name('productos.sincronizarPreciosMl');
    Route::post('productos/sincronizar-precios-tn', [TiendanubeVentaController::class, 'sincronizarPrecios'])->name('productos.sincronizarPreciosTn');
    Route::post('productos/{producto}/stock', [StockController::class, 'ajuste'])->name('productos.stock.ajuste');
    Route::post('productos/{producto}/transferencia', [StockController::class, 'transferencia'])->name('productos.stock.transferencia');
    Route::get('productos/{producto}/movimientos', [StockController::class, 'movimientos'])->name('productos.movimientos');
    Route::resource('productos', ProductoController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // Base de Datos → Proveedores
    Route::get('proveedores/data', [ProveedorController::class, 'data'])->name('proveedores.data');
    Route::get('proveedores/stats', [ProveedorController::class, 'stats'])->name('proveedores.stats');
    Route::get('proveedores/opciones', [ProveedorController::class, 'opciones'])->name('proveedores.opciones');
    Route::get('proveedores/verificar-documento', [ProveedorController::class, 'verificarDocumento'])->name('proveedores.verificar-documento');
    Route::get('proveedores/export', [ProveedorController::class, 'export'])->name('proveedores.export');
    Route::patch('proveedores/{proveedor}/estado', [ProveedorController::class, 'estado'])->name('proveedores.estado');
    Route::resource('proveedores', ProveedorController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['proveedores' => 'proveedor']);

    // Base de Datos → Importar Datos (spec 006-importar-datos-excel)
    Route::get('importar-datos/{entidad}', [ImportacionController::class, 'index'])->name('importacion.index');
    Route::post('importar-datos/{entidad}/subir', [ImportacionController::class, 'subir'])->name('importacion.subir');
    Route::get('importar-datos/{entidad}/mapear', [ImportacionController::class, 'mapear'])->name('importacion.mapear');
    Route::post('importar-datos/{entidad}/confirmar', [ImportacionController::class, 'confirmar'])->name('importacion.confirmar');
    Route::post('importar-datos/{entidad}/prevalidar', [ImportacionController::class, 'prevalidar'])->name('importacion.prevalidar');
    Route::post('importar-datos/{entidad}/confirmar-lote', [ImportacionController::class, 'confirmarLote'])->name('importacion.confirmar-lote');
    Route::post('importar-datos/{entidad}/cancelar', [ImportacionController::class, 'cancelar'])->name('importacion.cancelar');
    Route::get('importar-datos/{entidad}/resumen', [ImportacionController::class, 'resumen'])->name('importacion.resumen');

    // Deshacer import (spec 078) — sólo Productos & Servicios
    Route::get('importar-datos/{entidad}/historial', [ImportacionController::class, 'historial'])->name('importacion.historial');
    Route::get('importar-datos/{entidad}/historial/datos', [ImportacionController::class, 'historialDatos'])->name('importacion.historial.datos');
    Route::post('importar-datos/{entidad}/historial/{corrida}/deshacer', [ImportacionController::class, 'deshacer'])->name('importacion.deshacer');

    // Spec 093 — informe de qué cambió y descarga del archivo conservado.
    // La descarga hereda el permiso de la pantalla de importación (FR-014), no inventa uno nuevo.
    Route::get('importar-datos/{entidad}/historial/{corrida}/informe', [ImportacionController::class, 'informe'])->name('importacion.informe');
    Route::middleware('permiso:configuracion.importar')->group(function () {
        Route::get('importar-datos/{entidad}/historial/{corrida}/archivo', [ImportacionController::class, 'descargarArchivo'])->name('importacion.archivo');
    });

    // ==========================================================================================
    // INFORMES (spec 090 — un permiso por informe)
    //
    // Cada informe tiene su propio permiso `informes.<informe>`; las descargas encadenan además
    // `informes.exportar` (mismo patrón con que Tesorería eleva `tesoreria.ver` → `tesoreria.editar`
    // en `cuentas/orden`). Laravel ejecuta la pila en orden, así que dos instancias del alias
    // producen la conjunción: `informes.exportar` por sí solo no abre ningún informe, porque el
    // primer middleware ya corta.
    //
    // Hasta la spec 090 todo el módulo iba con un único `informes.ver`, y los bloques de Stock y
    // Cuenta Corriente Clientes habían quedado FUERA del grupo: cualquier usuario autenticado los
    // abría y exportaba pegando la URL, porque el `@can` del sidebar sólo escondía el enlace.
    // Por eso toda ruta del módulo nace ahora dentro de un sub-grupo con permiso.
    // ==========================================================================================

    // Informes → Stock
    Route::middleware('permiso:informes.stock')->group(function () {
        Route::get('informes/stock', [InformeStockController::class, 'index'])->name('informes.stock.index');
        Route::get('informes/stock/data', [InformeStockController::class, 'data'])->name('informes.stock.data');
        Route::get('informes/stock/stats', [InformeStockController::class, 'stats'])->name('informes.stock.stats');
    });

    // Informes → Cuenta Corriente Clientes (spec 029)
    Route::middleware('permiso:informes.cuenta-corriente-clientes')->group(function () {
        Route::get('informes/cuenta-corriente', [CuentaCorrienteController::class, 'index'])->name('informes.cuenta-corriente.index');
        Route::get('informes/cuenta-corriente/saldos', [CuentaCorrienteController::class, 'saldosData'])->name('informes.cuenta-corriente.saldos.data');
        Route::get('informes/cuenta-corriente/movimientos', [CuentaCorrienteController::class, 'movimientosData'])->name('informes.cuenta-corriente.movimientos.data');

        Route::middleware('permiso:informes.exportar')->group(function () {
            Route::get('informes/cuenta-corriente/exportar', [CuentaCorrienteController::class, 'exportar'])->name('informes.cuenta-corriente.exportar');
            Route::get('informes/cuenta-corriente/pdf', [CuentaCorrienteController::class, 'pdf'])->name('informes.cuenta-corriente.pdf');
            Route::get('informes/cuenta-corriente/movimientos/exportar', [CuentaCorrienteController::class, 'exportarMovimientos'])->name('informes.cuenta-corriente.movimientos.exportar');
            Route::get('informes/cuenta-corriente/movimientos/pdf', [CuentaCorrienteController::class, 'pdfMovimientos'])->name('informes.cuenta-corriente.movimientos.pdf');
        });
    });

    // Informes → Compras (spec 067, tanda 1). Sólo lectura salvo las vistas guardadas del pivot.
    Route::middleware('permiso:informes.compras')->group(function () {
        Route::get('informes/compras', [InformeComprasController::class, 'index'])->name('informes.compras.index');
        Route::get('informes/compras/data', [InformeComprasController::class, 'data'])->name('informes.compras.data');
        Route::get('informes/compras/stats', [InformeComprasController::class, 'stats'])->name('informes.compras.stats');

        Route::middleware('permiso:informes.exportar')->group(function () {
            Route::get('informes/compras/exportar', [InformeComprasController::class, 'exportar'])->name('informes.compras.exportar');
            Route::get('informes/compras/pdf', [InformeComprasController::class, 'pdf'])->name('informes.compras.pdf');
        });

        // Rankings y "Arma tu Informe" (spec 069, tanda 3). Las vistas guardadas SÍ escriben, pero
        // no llevan permiso propio (spec 069 FR-042 / spec 090 FR-020): quien puede ver el informe
        // puede guardar y borrar cruces sobre él.
        Route::prefix('informes/compras/pivot')->name('informes.compras.pivot.')->group(function () {
            Route::get('dataset', [InformeComprasController::class, 'pivotDataset'])->name('dataset');
            Route::post('exportar', [InformeComprasController::class, 'pivotExportar'])->middleware('permiso:informes.exportar')->name('exportar');

            Route::get('vistas', [InformeVistaController::class, 'indexCompras'])->name('vistas.index');
            Route::post('vistas', [InformeVistaController::class, 'storeCompras'])->name('vistas.store');
            Route::put('vistas/{vista}', [InformeVistaController::class, 'updateCompras'])->name('vistas.update');
            Route::delete('vistas/{vista}', [InformeVistaController::class, 'destroyCompras'])->name('vistas.destroy');
        });

        // Entrada directa por URL a un ranking o a una vista guardada (spec 069 research R6). Sin
        // esto, compartir el enlace de un ranking daba 404: la pestaña sólo existía en el cliente.
        Route::get('informes/compras/ranking/{dimension}', [InformeComprasController::class, 'index'])->name('informes.compras.ranking.show');
        Route::get('informes/compras/vista/{vista}', [InformeComprasController::class, 'index'])->name('informes.compras.vista.show');
    });

    // Informes → Gastos (spec 067, tanda 1)
    Route::middleware('permiso:informes.gastos')->group(function () {
        Route::get('informes/gastos', [InformeGastosController::class, 'index'])->name('informes.gastos.index');
        Route::get('informes/gastos/data', [InformeGastosController::class, 'data'])->name('informes.gastos.data');
        Route::get('informes/gastos/stats', [InformeGastosController::class, 'stats'])->name('informes.gastos.stats');
        Route::get('informes/gastos/grupo', [InformeGastosController::class, 'grupo'])->name('informes.gastos.grupo');

        Route::middleware('permiso:informes.exportar')->group(function () {
            Route::get('informes/gastos/exportar', [InformeGastosController::class, 'exportar'])->name('informes.gastos.exportar');
            Route::get('informes/gastos/pdf', [InformeGastosController::class, 'pdf'])->name('informes.gastos.pdf');
        });
    });

    // Informes → Cuenta Corriente Proveedores (spec 067, tanda 1)
    Route::middleware('permiso:informes.cuenta-corriente-proveedores')
        ->prefix('informes/cuenta-corriente-proveedores')
        ->name('informes.cuenta-corriente-proveedores.')
        ->group(function () {
            Route::get('/', [CuentaCorrienteProveedorController::class, 'index'])->name('index');
            Route::get('saldos', [CuentaCorrienteProveedorController::class, 'saldosData'])->name('saldos.data');
            Route::get('movimientos', [CuentaCorrienteProveedorController::class, 'movimientosData'])->name('movimientos.data');
            Route::get('proveedor/{proveedor}', [CuentaCorrienteProveedorController::class, 'showProveedor'])->name('proveedor.show');

            Route::middleware('permiso:informes.exportar')->group(function () {
                Route::get('exportar', [CuentaCorrienteProveedorController::class, 'exportar'])->name('exportar');
                Route::get('pdf', [CuentaCorrienteProveedorController::class, 'pdf'])->name('pdf');
                Route::get('movimientos/exportar', [CuentaCorrienteProveedorController::class, 'exportarMovimientos'])->name('movimientos.exportar');
                Route::get('movimientos/pdf', [CuentaCorrienteProveedorController::class, 'pdfMovimientos'])->name('movimientos.pdf');
            });
        });

    // Informes → Ventas (spec 068, tanda 2)
    Route::middleware('permiso:informes.ventas')->group(function () {
        Route::get('informes/ventas', [InformeVentasController::class, 'index'])->name('informes.ventas.index');
        Route::get('informes/ventas/data', [InformeVentasController::class, 'data'])->name('informes.ventas.data');
        Route::get('informes/ventas/stats', [InformeVentasController::class, 'stats'])->name('informes.ventas.stats');

        Route::middleware('permiso:informes.exportar')->group(function () {
            Route::get('informes/ventas/exportar', [InformeVentasController::class, 'exportar'])->name('informes.ventas.exportar');
            Route::get('informes/ventas/exportar-detallado', [InformeVentasController::class, 'exportarDetallado'])->name('informes.ventas.exportar-detallado');
            Route::get('informes/ventas/pdf', [InformeVentasController::class, 'pdf'])->name('informes.ventas.pdf');
        });

        Route::prefix('informes/ventas/pivot')->name('informes.ventas.pivot.')->group(function () {
            Route::get('dataset', [InformeVentasController::class, 'pivotDataset'])->name('dataset');
            Route::post('exportar', [InformeVentasController::class, 'pivotExportar'])->middleware('permiso:informes.exportar')->name('exportar');

            Route::get('vistas', [InformeVistaController::class, 'indexVentas'])->name('vistas.index');
            Route::post('vistas', [InformeVistaController::class, 'storeVentas'])->name('vistas.store');
            Route::put('vistas/{vista}', [InformeVistaController::class, 'updateVentas'])->name('vistas.update');
            Route::delete('vistas/{vista}', [InformeVistaController::class, 'destroyVentas'])->name('vistas.destroy');
        });

        Route::get('informes/ventas/ranking/{dimension}', [InformeVentasController::class, 'index'])->name('informes.ventas.ranking.show');
        Route::get('informes/ventas/vista/{vista}', [InformeVentasController::class, 'index'])->name('informes.ventas.vista.show');
    });

    // Informes → Reporte Final (spec 068, tanda 2). El más sensible: expone márgenes y CMV.
    Route::middleware('permiso:informes.reporte-final')
        ->prefix('informes/reporte-final')
        ->name('informes.reporte-final.')
        ->group(function () {
            Route::get('/', [ReporteFinalController::class, 'index'])->name('index');
            Route::get('data', [ReporteFinalController::class, 'data'])->name('data');

            Route::middleware('permiso:informes.exportar')->group(function () {
                Route::get('exportar', [ReporteFinalController::class, 'exportar'])->name('exportar');
                Route::get('pdf', [ReporteFinalController::class, 'pdf'])->name('pdf');
            });
        });

    // Informes → Información para tu Contador (spec 077): Libro IVA Ventas / Compras.
    // `data`/`stats` van por POST (spec 077 research §D9 — incidente 414 de Nginx).
    Route::middleware('permiso:informes.contador')->group(function () {
        Route::get('informes/contador', [InformeContadorController::class, 'index'])->name('informes.contador.index');
        Route::post('informes/contador/ventas/data', [InformeContadorController::class, 'ventasData'])->name('informes.contador.ventas.data');
        Route::post('informes/contador/ventas/stats', [InformeContadorController::class, 'ventasStats'])->name('informes.contador.ventas.stats');
        Route::post('informes/contador/compras/data', [InformeContadorController::class, 'comprasData'])->name('informes.contador.compras.data');
        Route::post('informes/contador/compras/stats', [InformeContadorController::class, 'comprasStats'])->name('informes.contador.compras.stats');

        Route::middleware('permiso:informes.exportar')->group(function () {
            Route::get('informes/contador/ventas/exportar', [InformeContadorController::class, 'ventasExportar'])->name('informes.contador.ventas.exportar');
            Route::get('informes/contador/compras/exportar', [InformeContadorController::class, 'comprasExportar'])->name('informes.contador.compras.exportar');
            // IVA Digital (spec 086): descarga del ZIP con los 4 TXT del régimen RG 3685.
            Route::get('informes/contador/iva-digital', [InformeContadorController::class, 'ivaDigital'])->name('informes.contador.iva-digital');
        });

        // Enviar Información a tu Contador por Correo (spec 087). No exige `informes.exportar`:
        // no es una descarga para el usuario, es un envío del sistema (spec 090 FR-012).
        Route::post('informes/contador/adjuntos-previstos', [EnvioContadorController::class, 'adjuntosPrevistos'])->name('informes.contador.adjuntos-previstos');
        Route::post('informes/contador/enviar', [EnvioContadorController::class, 'enviar'])->name('informes.contador.enviar');
        Route::get('informes/contador/envios', [EnvioContadorController::class, 'historial'])->name('informes.contador.envios');
        Route::get('informes/contador/envios/{envio}', [EnvioContadorController::class, 'estado'])->name('informes.contador.envio-estado');
    });

    // Tesorería (spec 007) — Saldos, Movimientos, config de cuentas, transferencias, ficha/ledger
    Route::middleware('permiso:tesoreria.ver')->prefix('tesoreria')->name('tesoreria.')->group(function () {
        Route::get('/', [TesoreriaController::class, 'saldos'])->name('saldos');
        Route::get('saldos/data', [TesoreriaController::class, 'saldosData'])->name('saldos.data');
        Route::get('movimientos', [TesoreriaController::class, 'movimientos'])->name('movimientos');
        Route::get('movimientos/data', [TesoreriaController::class, 'movimientosData'])->name('movimientos.data');
        Route::get('movimientos/pdf', [TesoreriaController::class, 'movimientosPdf'])->name('movimientos.pdf');
        Route::get('movimientos/export', [TesoreriaController::class, 'movimientosExport'])->name('movimientos.export');
        Route::get('config/cuentas', [TesoreriaController::class, 'configCuentas'])->name('config.data');
        Route::get('cuentas/opciones', [TesoreriaController::class, 'cuentasOpciones'])->name('cuentas.opciones');
        Route::post('transferencias', [TesoreriaController::class, 'transferir'])->name('transferencias.store');

        Route::post('cuentas', [CuentaTesoreriaController::class, 'store'])->name('cuentas.store');
        // Reordenar cuentas de un bloque (spec 085). Va ANTES de `cuentas/{cuenta}` para que
        // un futuro PATCH con parámetro no capture `orden` como si fuera un id. Eleva el
        // permiso del grupo (`tesoreria.ver`) a `tesoreria.editar`: reordenar escribe.
        Route::patch('cuentas/orden', [TesoreriaController::class, 'reordenarCuentas'])->middleware('permiso:tesoreria.editar')->name('cuentas.orden');
        Route::get('cuentas/{cuenta}', [CuentaTesoreriaController::class, 'show'])->name('cuentas.show');
        Route::get('cuentas/{cuenta}/data', [CuentaTesoreriaController::class, 'data'])->name('cuentas.data');
        Route::get('cuentas/{cuenta}/export', [CuentaTesoreriaController::class, 'export'])->name('cuentas.export');
        Route::put('cuentas/{cuenta}', [CuentaTesoreriaController::class, 'update'])->name('cuentas.update');
        Route::delete('cuentas/{cuenta}', [CuentaTesoreriaController::class, 'destroy'])->name('cuentas.destroy');

        Route::put('movimientos/{movimiento}', [CuentaTesoreriaController::class, 'updateMovimiento'])->name('movimientos.update');
        Route::delete('movimientos/{movimiento}', [CuentaTesoreriaController::class, 'destroyMovimiento'])->name('movimientos.destroy');
    });

    // Ingresos (spec 008) — Presupuestos, Ventas + Cobranza/NC-ND/Remitos, Otros Ingresos
    Route::middleware('permiso:presupuestos.ver')->prefix('presupuestos')->name('presupuestos.')->group(function () {
        Route::get('/', [PresupuestoController::class, 'index'])->name('index');
        Route::get('data', [PresupuestoController::class, 'data'])->name('data');
        Route::get('kpis', [PresupuestoController::class, 'kpisData'])->name('kpis');
        Route::get('nuevo', [PresupuestoController::class, 'create'])->name('create');
        Route::post('/', [PresupuestoController::class, 'store'])->name('store');
        Route::get('{presupuesto}/editar', [PresupuestoController::class, 'edit'])->name('edit');
        Route::put('{presupuesto}', [PresupuestoController::class, 'update'])->name('update');
        Route::delete('{presupuesto}', [PresupuestoController::class, 'destroy'])->name('destroy');
        Route::patch('{presupuesto}/estado', [PresupuestoController::class, 'estado'])->name('estado');
        Route::get('{presupuesto}/pdf', [PresupuestoController::class, 'pdf'])->name('pdf');
        Route::post('{presupuesto}/crear-venta', [PresupuestoController::class, 'crearVenta'])->name('crearVenta');
        Route::get('{presupuesto}', [PresupuestoController::class, 'show'])->name('show');
    });

    Route::middleware('permiso:ventas.ver')->prefix('ventas')->name('ventas.')->group(function () {
        Route::get('/', [VentaController::class, 'index'])->name('index');
        Route::get('data', [VentaController::class, 'data'])->name('data');
        Route::get('kpis', [VentaController::class, 'kpisData'])->name('kpis');
        Route::get('nueva', [VentaController::class, 'create'])->name('create');
        Route::post('/', [VentaController::class, 'store'])->name('store');
        Route::get('{venta}/editar', [VentaController::class, 'edit'])->name('edit');
        Route::put('{venta}', [VentaController::class, 'update'])->name('update');
        Route::delete('{venta}', [VentaController::class, 'destroy'])->name('destroy');
        Route::get('{venta}/pdf', [VentaController::class, 'pdf'])->name('pdf');
        Route::get('{venta}/ticket', [VentaController::class, 'ticket'])->name('ticket');
        Route::post('{venta}/enviar-arca', [VentaController::class, 'enviarArca'])->name('enviarArca');
        // Datos del modal de Cobranza para abrirlo desde el menú de fila del listado (ver Compras).
        Route::get('{venta}/cobranza-contexto', [VentaController::class, 'cobranzaContexto'])->name('cobranzas.contexto');
        Route::post('{venta}/cobranzas', [VentaController::class, 'cobranzaStore'])->name('cobranzas.store');
        Route::put('{venta}/cobranzas/{cobro}', [VentaController::class, 'cobranzaUpdate'])->name('cobranzas.update');
        Route::delete('{venta}/cobranzas/{cobro}', [VentaController::class, 'cobranzaDestroy'])->name('cobranzas.destroy');
        Route::get('{venta}/cobranzas/{cobro}/recibo', [VentaController::class, 'reciboCobranza'])->name('cobranzas.recibo');
        // Saldo a favor (spec 072): mismo grupo y por lo tanto mismo permiso que la cobranza — no
        // se crea un permiso nuevo (FR-022).
        Route::get('{venta}/credito-disponible', [AplicacionCreditoController::class, 'disponibleVenta'])->name('credito.disponible');
        Route::post('{venta}/aplicaciones-credito', [AplicacionCreditoController::class, 'storeVenta'])->name('aplicaciones-credito.store');
        Route::delete('{venta}/aplicaciones-credito/{aplicacion}', [AplicacionCreditoController::class, 'destroyVenta'])->name('aplicaciones-credito.destroy');
        Route::get('{venta}/notas/nueva', [NotaCreditoDebitoController::class, 'create'])->name('notas.create');
        Route::get('{venta}/notas/{notaCreditoDebito}/editar', [NotaCreditoDebitoController::class, 'edit'])->name('notas.edit');
        Route::post('{venta}/notas', [NotaCreditoDebitoController::class, 'store'])->name('notas.store');
        Route::put('{venta}/notas/{notaCreditoDebito}', [NotaCreditoDebitoController::class, 'update'])->name('notas.update');
        Route::delete('{venta}/notas/{notaCreditoDebito}', [NotaCreditoDebitoController::class, 'destroy'])->name('notas.destroy');
        Route::get('notas/{notaCreditoDebito}/pdf', [NotaCreditoDebitoController::class, 'pdf'])->name('notas.pdf');
        Route::get('{venta}/notas-credito-debito/items-disponibles', [NotaCreditoDebitoController::class, 'itemsDisponiblesVenta'])->name('notas.itemsDisponibles');
        // Spec 097: envío manual a ARCA de una NC/ND — reemplaza el trigger automático de store().
        Route::post('{venta}/notas/{notaCreditoDebito}/enviar-arca', [NotaCreditoDebitoController::class, 'enviarArca'])->name('notas.enviarArca');
        Route::get('{venta}/remitos/nuevo', [RemitoController::class, 'create'])->name('remitos.create');
        Route::post('{venta}/remitos', [RemitoController::class, 'store'])->name('remitos.store');
        Route::get('{venta}/remitos/{remito}/editar', [RemitoController::class, 'edit'])->name('remitos.edit');
        Route::put('{venta}/remitos/{remito}', [RemitoController::class, 'update'])->name('remitos.update');
        Route::delete('{venta}/remitos/{remito}', [RemitoController::class, 'destroy'])->name('remitos.destroy');
        Route::get('{venta}', [VentaController::class, 'show'])->name('show');
    });

    Route::middleware('permiso:otros-ingresos.ver')->prefix('otros-ingresos')->name('otros-ingresos.')->group(function () {
        Route::get('/', [OtroIngresoController::class, 'index'])->name('index');
        Route::get('data', [OtroIngresoController::class, 'data'])->name('data');
        Route::post('/', [OtroIngresoController::class, 'store'])->name('store');
        Route::put('{otroIngreso}', [OtroIngresoController::class, 'update'])->name('update');
        Route::delete('{otroIngreso}', [OtroIngresoController::class, 'destroy'])->name('destroy');
    });

    // Ingresos → Mercado Libre (spec 012) — listado de órdenes, sincronización y conversión a Venta
    Route::middleware('permiso:ventas.ver')->prefix('ingresos/mercadolibre')->name('ingresos.mercadolibre.')->group(function () {
        Route::get('/', [MercadoLibreVentaController::class, 'index'])->name('index');
        Route::get('datatable', [MercadoLibreVentaController::class, 'datatable'])->name('datatable');
        Route::post('sincronizar', [MercadoLibreVentaController::class, 'sincronizar'])->name('sincronizar');
        Route::post('sincronizar-stock', [MercadoLibreVentaController::class, 'sincronizarStock'])->name('sincronizarStock');
        Route::post('transformar-todas-en-venta', [MercadoLibreVentaController::class, 'transformarTodasEnVenta'])->name('transformarTodasEnVenta');
        Route::prefix('vinculaciones')->name('vinculaciones.')->group(function () {
            Route::get('/', [MercadoLibreVinculacionController::class, 'index'])->name('index');
            Route::get('datatable', [MercadoLibreVinculacionController::class, 'datatable'])->name('datatable');
            Route::get('pendientes', [MercadoLibreVinculacionController::class, 'publicacionesPendientes'])->name('pendientes');
            Route::post('vincular-automaticamente', [MercadoLibreVinculacionController::class, 'vincularAutomaticamente'])->name('vincularAutomaticamente');
            Route::post('sincronizacion-forzada', [MercadoLibreVinculacionController::class, 'sincronizacionForzada'])->name('sincronizacionForzada');
            Route::get('sincronizacion-forzada/estado', [MercadoLibreVinculacionController::class, 'sincronizacionForzadaEstado'])->name('sincronizacionForzada.estado');
            Route::delete('/', [MercadoLibreVinculacionController::class, 'eliminarTodas'])->name('eliminarTodas');
            Route::patch('{vinculacion}', [MercadoLibreVinculacionController::class, 'update'])->name('update');
            Route::delete('{vinculacion}', [MercadoLibreVinculacionController::class, 'destroy'])->name('destroy');
            // spec 063 (T025/FR-017): reactiva una publicación bloqueada por error permanente.
            Route::post('{vinculacion}/reactivar', [MercadoLibreVinculacionController::class, 'reactivar'])->name('reactivar');
        });

        // Corte de seguridad de precios (spec 084, US1): los envíos frenados se resuelven desde
        // Vinculaciones, que es donde ya vive el estado por publicación. Va en este grupo y no en
        // el de Configuración para que alcance con el mismo permiso que ver la pantalla.
        Route::prefix('retenciones-precio')->name('retencionesPrecio.')->group(function () {
            Route::get('/', [MercadoLibreRetencionPrecioController::class, 'index'])->name('index');
            Route::post('{retencion}/aprobar', [MercadoLibreRetencionPrecioController::class, 'aprobar'])->name('aprobar');
            Route::post('{retencion}/rechazar', [MercadoLibreRetencionPrecioController::class, 'rechazar'])->name('rechazar');
        });

        // Rutas con {orden} genérico DEBEN ir después de /vinculaciones — si no, "vinculaciones"
        // matchea {orden} primero y la pantalla de vinculaciones nunca se alcanza (bug detectado
        // en el deploy del 28/07/2026).
        Route::get('{orden}/convertir', [MercadoLibreVentaController::class, 'convertir'])->name('convertir');
        Route::post('{orden}/convertir', [MercadoLibreVentaController::class, 'convertirGuardar'])->name('convertirGuardar');
        // spec 063 (T013/T014): descarta el aviso de cancelación/reembolso/mediación posterior a la
        // conversión, dejando la Venta vigente tal cual está (FR-010/FR-011).
        Route::post('{orden}/descartar-aviso', [MercadoLibreVentaController::class, 'descartarAviso'])->name('descartarAviso');
        Route::get('{orden}', [MercadoLibreVentaController::class, 'show'])->name('show');
    });

    // Ingresos → Tiendanube (spec 017) — listado de órdenes, sincronización y conversión a Venta
    Route::middleware('permiso:ventas.ver')->prefix('ingresos/tiendanube')->name('ingresos.tiendanube.')->group(function () {
        Route::get('/', [TiendanubeVentaController::class, 'index'])->name('index');
        Route::get('datatable', [TiendanubeVentaController::class, 'datatable'])->name('datatable');
        Route::post('sincronizar', [TiendanubeVentaController::class, 'sincronizar'])->name('sincronizar');
        Route::post('sincronizar-stock', [TiendanubeVentaController::class, 'sincronizarStock'])->name('sincronizarStock');
        Route::post('transformar-todas-en-venta', [TiendanubeVentaController::class, 'transformarTodasEnVenta'])->name('transformarTodasEnVenta');
        Route::prefix('vinculaciones')->name('vinculaciones.')->group(function () {
            Route::get('/', [TiendanubeVinculacionController::class, 'index'])->name('index');
            Route::get('datatable', [TiendanubeVinculacionController::class, 'datatable'])->name('datatable');
            Route::post('vincular-automaticamente', [TiendanubeVinculacionController::class, 'vincularAutomaticamente'])->name('vincularAutomaticamente');
            Route::post('sincronizacion-forzada', [TiendanubeVinculacionController::class, 'sincronizacionForzada'])->name('sincronizacionForzada');
            Route::get('sincronizacion-forzada/estado', [TiendanubeVinculacionController::class, 'sincronizacionForzadaEstado'])->name('sincronizacionForzada.estado');
            Route::delete('/', [TiendanubeVinculacionController::class, 'eliminarTodas'])->name('eliminarTodas');
            Route::patch('{vinculacion}', [TiendanubeVinculacionController::class, 'update'])->name('update');
            Route::delete('{vinculacion}', [TiendanubeVinculacionController::class, 'destroy'])->name('destroy');
        });

        // Rutas con {orden} genérico DEBEN ir después de /vinculaciones — mismo cuidado que
        // dejó documentado la spec 012 (si no, "vinculaciones" matchea {orden} primero).
        Route::get('{orden}/convertir', [TiendanubeVentaController::class, 'convertir'])->name('convertir');
        Route::post('{orden}/convertir', [TiendanubeVentaController::class, 'convertirGuardar'])->name('convertirGuardar');
        Route::get('{orden}', [TiendanubeVentaController::class, 'show'])->name('show');
    });

    Route::post('categorias-ingreso', [CategoriaController::class, 'storeIngreso'])->name('categorias.ingreso.store');
    Route::post('categorias-venta', [CategoriaController::class, 'storeVenta'])->name('categorias.venta.store');

    // Vendedores (spec 020) — ABM inline único, usado desde Venta, Presupuesto y config. Tiendanube/MercadoLibre.
    Route::post('vendedores', [VendedorController::class, 'store'])->name('vendedores.store');
    Route::patch('vendedores/{vendedor}', [VendedorController::class, 'update'])->name('vendedores.update');
    Route::delete('vendedores/{vendedor}', [VendedorController::class, 'destroy'])->name('vendedores.destroy');

    // Remitos (spec 064) — documento imprimible global (Ventas y Compras comparten el mismo id) y
    // alta al vuelo de Transportista, sin pantalla de ABM propia (FR-021 a FR-023).
    Route::get('remitos/{remito}/pdf', [RemitoController::class, 'pdf'])->name('remitos.pdf');
    Route::get('transportistas/opciones', [TransportistaController::class, 'opciones'])->name('transportistas.opciones');
    Route::post('transportistas', [TransportistaController::class, 'store'])->name('transportistas.store');

    // Egresos (spec 009) — Compras (+ Pagos/Retenciones/NC-ND/Remitos) y Gastos
    Route::middleware('permiso:compras.ver')->prefix('compras')->name('compras.')->group(function () {
        Route::get('/', [CompraController::class, 'index'])->name('index');
        Route::get('data', [CompraController::class, 'data'])->name('data');
        Route::get('kpis', [CompraController::class, 'kpisData'])->name('kpis');
        Route::get('nueva', [CompraController::class, 'create'])->name('create');
        Route::post('/', [CompraController::class, 'store'])->name('store');
        Route::get('{compra}/editar', [CompraController::class, 'edit'])->name('edit');
        Route::put('{compra}', [CompraController::class, 'update'])->name('update');
        Route::delete('{compra}', [CompraController::class, 'destroy'])->name('destroy');
        Route::get('{compra}/pdf', [CompraController::class, 'pdf'])->name('pdf');
        // Datos del modal de Pago para abrirlo desde el menú de fila del listado, sin ir a la ficha.
        Route::get('{compra}/pago-contexto', [CompraController::class, 'pagoContexto'])->name('pagos.contexto');
        Route::post('{compra}/pagos', [CompraController::class, 'pagoStore'])->name('pagos.store');
        Route::put('{compra}/pagos/{pago}', [CompraController::class, 'pagoUpdate'])->name('pagos.update');
        Route::delete('{compra}/pagos/{pago}', [CompraController::class, 'pagoDestroy'])->name('pagos.destroy');
        Route::get('{compra}/pagos/{pago}/recibo', [CompraController::class, 'reciboPago'])->name('pagos.recibo');
        // Saldo a favor de proveedor (spec 072, US4): mismo permiso que registrar un pago.
        Route::get('{compra}/credito-disponible', [AplicacionCreditoController::class, 'disponibleCompra'])->name('credito.disponible');
        Route::post('{compra}/aplicaciones-credito', [AplicacionCreditoController::class, 'storeCompra'])->name('aplicaciones-credito.store');
        Route::delete('{compra}/aplicaciones-credito/{aplicacion}', [AplicacionCreditoController::class, 'destroyCompra'])->name('aplicaciones-credito.destroy');
        Route::post('{compra}/retenciones', [CompraController::class, 'retencionStore'])->name('retenciones.store');
        Route::get('{compra}/notas/nueva', [NotaCreditoDebitoController::class, 'createCompra'])->name('notas.create');
        Route::get('{compra}/notas/{notaCreditoDebito}/editar', [NotaCreditoDebitoController::class, 'editCompra'])->name('notas.edit');
        Route::post('{compra}/notas', [NotaCreditoDebitoController::class, 'storeCompra'])->name('notas.store');
        Route::put('{compra}/notas/{notaCreditoDebito}', [NotaCreditoDebitoController::class, 'updateCompra'])->name('notas.update');
        Route::delete('{compra}/notas/{notaCreditoDebito}', [NotaCreditoDebitoController::class, 'destroyCompra'])->name('notas.destroy');
        Route::get('notas/{notaCreditoDebito}/pdf', [NotaCreditoDebitoController::class, 'pdf'])->name('notas.pdf');
        Route::get('{compra}/notas-credito-debito/items-disponibles', [NotaCreditoDebitoController::class, 'itemsDisponiblesCompra'])->name('notas.itemsDisponibles');
        // Spec 097: paridad Compras (FR-011).
        Route::post('{compra}/notas/{notaCreditoDebito}/enviar-arca', [NotaCreditoDebitoController::class, 'enviarArcaCompra'])->name('notas.enviarArca');
        Route::get('{compra}/remitos/nuevo', [RemitoController::class, 'createCompra'])->name('remitos.create');
        Route::post('{compra}/remitos', [RemitoController::class, 'storeCompra'])->name('remitos.store');
        Route::get('{compra}/remitos/{remito}/editar', [RemitoController::class, 'editCompra'])->name('remitos.edit');
        Route::put('{compra}/remitos/{remito}', [RemitoController::class, 'updateCompra'])->name('remitos.update');
        Route::delete('{compra}/remitos/{remito}', [RemitoController::class, 'destroyCompra'])->name('remitos.destroy');
        Route::get('{compra}', [CompraController::class, 'show'])->name('show');
    });

    Route::middleware('permiso:gastos.ver')->prefix('gastos')->name('gastos.')->group(function () {
        Route::get('/', [GastoController::class, 'index'])->name('index');
        Route::get('data', [GastoController::class, 'data'])->name('data');
        Route::post('/', [GastoController::class, 'store'])->name('store');
        Route::put('{gasto}', [GastoController::class, 'update'])->name('update');
        Route::delete('{gasto}', [GastoController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('permiso:auditoria.ver')->prefix('auditoria')->name('auditoria.')->group(function () {
        Route::get('/', [AuditoriaController::class, 'index'])->name('index');
        Route::get('data', [AuditoriaController::class, 'data'])->name('data');
        Route::get('exportar', [AuditoriaController::class, 'exportar'])->name('exportar');
        Route::get('{log}/detalle', [AuditoriaController::class, 'detalle'])->name('detalle');
    });

    Route::post('categorias-compra', [CategoriaController::class, 'storeCompra'])->name('categorias.compra.store');
    Route::post('categorias-gasto', [CategoriaController::class, 'storeGasto'])->name('categorias.gasto.store');
    Route::post('categorias-gasto/{categoria}/subcategorias', [CategoriaController::class, 'storeSubcategoriaGasto'])->name('categorias.gasto.subcategorias.store');
    Route::patch('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
    Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');

    // Base de Datos → Listas de precio (catálogo global, gestionable desde el modal de producto)
    Route::get('listas-precio', [ListaPrecioController::class, 'index'])->name('listas-precio.index');
    Route::post('listas-precio', [ListaPrecioController::class, 'store'])->name('listas-precio.store');
    Route::patch('listas-precio/{lista}', [ListaPrecioController::class, 'update'])->name('listas-precio.update');
    Route::delete('listas-precio/{lista}', [ListaPrecioController::class, 'destroy'])->name('listas-precio.destroy');

    // Base de Datos → Tipos de producto (catálogo, gestionable desde el modal de producto)
    Route::get('tipos-producto', [TipoProductoController::class, 'index'])->name('tipos-producto.index');
    Route::post('tipos-producto', [TipoProductoController::class, 'store'])->name('tipos-producto.store');
    Route::patch('tipos-producto/{tipo}', [TipoProductoController::class, 'update'])->name('tipos-producto.update');
    Route::delete('tipos-producto/{tipo}', [TipoProductoController::class, 'destroy'])->name('tipos-producto.destroy');

    // Configuración & Ajustes (spec 043: pantalla única con tabs, gate único rol Admin)
    Route::prefix('configuracion')->name('configuracion.')->group(function () {
        Route::middleware('admin')->group(function () {
            Route::get('/', [ConfiguracionController::class, 'index'])->name('index');
            Route::put('ventas', [ConfiguracionVentasController::class, 'guardar'])->name('ventas.guardar');
        });

        Route::middleware('admin')->prefix('usuarios')->name('usuarios.')->group(function () {
            Route::get('data', [UsuarioController::class, 'data'])->name('data');
            Route::post('/', [UsuarioController::class, 'store'])->name('store');
            Route::get('{usuario}', [UsuarioController::class, 'show'])->name('show');
            Route::put('{usuario}', [UsuarioController::class, 'update'])->name('update');
            Route::patch('{usuario}/estado', [UsuarioController::class, 'estado'])->name('estado');
        });
        Route::middleware('admin')->prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RolController::class, 'index'])->name('index');
            Route::get('data', [RolController::class, 'data'])->name('data');
            Route::get('catalogo-permisos', [RolController::class, 'permisos'])->name('permisos');
            Route::post('/', [RolController::class, 'store'])->name('store');
            Route::get('{rol}', [RolController::class, 'show'])->name('show');
            Route::put('{rol}', [RolController::class, 'update'])->name('update');
            Route::delete('{rol}', [RolController::class, 'destroy'])->name('destroy');
        });
        Route::middleware('admin')->prefix('depositos')->name('depositos.')->group(function () {
            Route::get('/', [DepositoController::class, 'index'])->name('index');
            Route::get('data', [DepositoController::class, 'data'])->name('data');
            Route::post('/', [DepositoController::class, 'store'])->name('store');
            Route::patch('{deposito}', [DepositoController::class, 'update'])->name('update');
            Route::patch('{deposito}/estado', [DepositoController::class, 'estado'])->name('estado');
            Route::delete('{deposito}', [DepositoController::class, 'destroy'])->name('destroy');
        });

        // Configuración & Ajustes → Funciones Avanzadas (spec 011)
        Route::middleware('admin')->prefix('funciones')->name('funciones.')->group(function () {
            Route::get('/', [FuncionAvanzadaController::class, 'index'])->name('index');
            Route::patch('{funcion}/estado', [FuncionAvanzadaController::class, 'estado'])->name('estado');
        });

        // Configuración & Ajustes → Mercado Libre (spec 011)
        Route::middleware('admin')->prefix('mercadolibre')->name('mercadolibre.')->group(function () {
            Route::get('/', [MercadoLibreConfiguracionController::class, 'index'])->name('index');
            Route::get('estado', [MercadoLibreConfiguracionController::class, 'estado'])->name('estado');
            Route::get('pendiente', [MercadoLibreConfiguracionController::class, 'pendiente'])->name('pendiente');
            Route::post('pendiente/confirmar', [MercadoLibreConfiguracionController::class, 'confirmarReemplazo'])->name('confirmarReemplazo');
            Route::delete('pendiente', [MercadoLibreConfiguracionController::class, 'descartarPendiente'])->name('descartarPendiente');
            Route::put('configuracion', [MercadoLibreConfiguracionController::class, 'guardar'])->name('guardar');
            Route::patch('ventas', [MercadoLibreConfiguracionController::class, 'guardarVentas'])->name('ventas.configurar');
            Route::patch('modo-solo-lectura', [MercadoLibreConfiguracionController::class, 'modoSoloLectura'])->name('modoSoloLectura');
            Route::post('probar', [MercadoLibreConfiguracionController::class, 'probar'])->name('probar');
            Route::delete('desconectar', [MercadoLibreConfiguracionController::class, 'desconectar'])->name('desconectar');
            Route::get('operaciones', [MercadoLibreConfiguracionController::class, 'operaciones'])->name('operaciones');
            Route::get('conectar', [MercadoLibreOAuthController::class, 'conectar'])->name('conectar');
            Route::get('callback', [MercadoLibreOAuthController::class, 'callback'])->name('callback');

            // Configuración del bot de sugerencias de IA (spec 033, US1) — nombre de
            // ruta 'bot' (no 'bot.index') porque `funciones_avanzadas.ruta_configuracion`
            // guarda 'configuracion.mercadolibre.bot' tal cual (mismo patrón que
            // 'configuracion.mercadolibre.index' para el resto de la integración).
            Route::get('bot', [MercadoLibreBotConfiguracionController::class, 'index'])->name('bot');
            Route::put('bot', [MercadoLibreBotConfiguracionController::class, 'guardar'])->name('bot.guardar');

            // Previa del cambio de Lista de Precios configurada (spec 084, US2). Las retenciones
            // en sí viven con Vinculaciones, que es la pantalla donde se resuelven.
            Route::post('ventas/previa', [MercadoLibreConfiguracionController::class, 'previaCambioLista'])->name('ventas.previa');
        });

        // Configuración & Ajustes → Tiendanube (spec 022/024: conexión Application REST clásica)
        Route::middleware('admin')->prefix('tiendanube')->name('tiendanube.')->group(function () {
            Route::get('/', [TiendanubeConfiguracionController::class, 'index'])->name('index');
            Route::patch('modo-solo-lectura', [TiendanubeConfiguracionController::class, 'modoSoloLectura'])->name('modoSoloLectura');
            Route::patch('ventas', [TiendanubeConfiguracionController::class, 'guardarVentas'])->name('ventas.configurar');
            Route::get('historial', [TiendanubeConfiguracionController::class, 'historial'])->name('historial');

            Route::get('conectar-rest', [TiendanubeConexionRestController::class, 'conectarRest'])->name('conectarRest');
            Route::get('callback-rest', [TiendanubeConexionRestController::class, 'callbackRest'])->name('callbackRest');
            Route::get('estado-rest', [TiendanubeConexionRestController::class, 'estadoRest'])->name('estadoRest');
            Route::post('desconectar-rest', [TiendanubeConexionRestController::class, 'desconectarRest'])->name('desconectarRest');
        });

        // Configuración & Ajustes → Mi Perfil (spec 039): datos fiscales del negocio emisor
        Route::middleware('admin')->prefix('mi-perfil')->name('mi-perfil.')->group(function () {
            Route::get('/', [MiPerfilController::class, 'index'])->name('index');
            Route::post('/', [MiPerfilController::class, 'guardar'])->name('guardar');
            Route::put('contrasena', [MiPerfilController::class, 'actualizarPassword'])->name('contrasena.actualizar');
        });

        // Configuración & Ajustes → Facturación Electrónica (spec 034: ARCA/AFIP)
        Route::middleware('admin')->prefix('arca')->name('arca.')->group(function () {
            Route::get('/', [FacturacionElectronicaController::class, 'index'])->name('index');
            Route::post('certificado', [FacturacionElectronicaController::class, 'guardarCertificado'])->name('certificado.guardar');
            Route::post('puntos-venta', [FacturacionElectronicaController::class, 'guardarPuntoVenta'])->name('puntos-venta.guardar');
            Route::patch('puntos-venta/{puntoVenta}/estado', [FacturacionElectronicaController::class, 'puntoVentaEstado'])->name('puntos-venta.estado');
        });
    });

    // Monitoreo (spec 073) — dejó de ser una URL secreta: tiene permiso propio, indicador en la
    // barra superior y notificaciones. Lectura con `monitoreo.ver`, escritura con `monitoreo.gestionar`.
    Route::prefix('monitoreo')->name('monitoreo.')->group(function () {
        $panel = MonitoreoController::class;
        $resumen = MonitoreoResumenController::class;

        Route::middleware('permiso:monitoreo.ver')->group(function () use ($panel, $resumen) {
            Route::get('/', [$panel, 'index'])->name('index');
            Route::get('pulso', [$panel, 'pulso'])->name('pulso');
            Route::get('publicaciones', [$panel, 'publicaciones'])->name('publicaciones');
            Route::get('reponer', [$panel, 'reponer'])->name('reponer');
            Route::get('riesgo-ml', [$panel, 'riesgoMl'])->name('riesgoMl');
            Route::get('sin-stock', [$panel, 'sinStock'])->name('sinStock');
            Route::get('ordenes', [$panel, 'ordenes'])->name('ordenes');
            Route::get('ventas', [$panel, 'ventas'])->name('ventas');
            // Corte de seguridad de precios (spec 084, US3): último chequeo CRM ↔ API.
            Route::get('precios-mercadolibre', [$panel, 'preciosMercadoLibre'])->name('preciosMercadoLibre');

            Route::get('resumen', [$resumen, 'resumen'])->name('resumen');
            // Marcar leído es una acción sobre el propio estado de lectura del usuario, no sobre
            // la integración: alcanza con `monitoreo.ver`.
            Route::post('notificaciones/leer', [$resumen, 'leer'])->name('notificaciones.leer');
        });

        Route::middleware('permiso:monitoreo.gestionar')->group(function () use ($panel) {
            Route::post('destrabar', [$panel, 'destrabar'])->name('destrabar');
            Route::post('reactivar', [$panel, 'reactivar'])->name('reactivar');
            Route::post('sincronizar', [$panel, 'sincronizar'])->name('sincronizar');
            Route::post('punto-reposicion', [$panel, 'puntoReposicion'])->name('puntoReposicion');
            Route::post('precios-mercadolibre/correr', [$panel, 'correrChequeoPrecios'])->name('preciosMercadoLibre.correr');
        });
    });

}); // fin Route::middleware('auth')
