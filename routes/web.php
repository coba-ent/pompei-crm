<?php

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
use App\Http\Controllers\Informes\InformeStockController;
use App\Http\Controllers\Ingresos\MercadoLibreVentaController;
use App\Http\Controllers\Ingresos\MercadoLibreVinculacionController;
use App\Http\Controllers\Ingresos\TiendanubeVentaController;
use App\Http\Controllers\Ingresos\TiendanubeVinculacionController;
use App\Http\Controllers\Integraciones\MercadoLibreBotConfiguracionController;
use App\Http\Controllers\Integraciones\MercadoLibreConfiguracionController;
use App\Http\Controllers\Integraciones\MercadoLibreMensajeriaWebhookController;
use App\Http\Controllers\Integraciones\MercadoLibreOAuthController;
use App\Http\Controllers\Integraciones\TiendanubeConexionRestController;
use App\Http\Controllers\Integraciones\TiendanubeConfiguracionController;
use App\Http\Controllers\Integraciones\TiendanubeWebhookController;
use App\Http\Controllers\ListaPrecioController;
use App\Http\Controllers\Mensajeria\ConversacionController;
use App\Http\Controllers\Mensajeria\SugerenciaController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Controllers\NotaCreditoDebitoController;
use App\Http\Controllers\OtroIngresoController;
use App\Http\Controllers\PresupuestoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TesoreriaController;
use App\Http\Controllers\TipoProductoController;
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
    Route::post('importar-datos/{entidad}/confirmar-lote', [ImportacionController::class, 'confirmarLote'])->name('importacion.confirmar-lote');
    Route::post('importar-datos/{entidad}/cancelar', [ImportacionController::class, 'cancelar'])->name('importacion.cancelar');
    Route::get('importar-datos/{entidad}/resumen', [ImportacionController::class, 'resumen'])->name('importacion.resumen');

    // Informes → Stock
    Route::get('informes/stock', [InformeStockController::class, 'index'])->name('informes.stock.index');
    Route::get('informes/stock/data', [InformeStockController::class, 'data'])->name('informes.stock.data');
    Route::get('informes/stock/stats', [InformeStockController::class, 'stats'])->name('informes.stock.stats');

    // Informes → Cuenta Corriente (spec 029)
    Route::get('informes/cuenta-corriente', [CuentaCorrienteController::class, 'index'])->name('informes.cuenta-corriente.index');
    Route::get('informes/cuenta-corriente/saldos', [CuentaCorrienteController::class, 'saldosData'])->name('informes.cuenta-corriente.saldos.data');
    Route::get('informes/cuenta-corriente/movimientos', [CuentaCorrienteController::class, 'movimientosData'])->name('informes.cuenta-corriente.movimientos.data');

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
        Route::get('nueva', [VentaController::class, 'create'])->name('create');
        Route::post('/', [VentaController::class, 'store'])->name('store');
        Route::get('{venta}/editar', [VentaController::class, 'edit'])->name('edit');
        Route::put('{venta}', [VentaController::class, 'update'])->name('update');
        Route::delete('{venta}', [VentaController::class, 'destroy'])->name('destroy');
        Route::get('{venta}/pdf', [VentaController::class, 'pdf'])->name('pdf');
        Route::get('{venta}/ticket', [VentaController::class, 'ticket'])->name('ticket');
        Route::post('{venta}/enviar-arca', [VentaController::class, 'enviarArca'])->name('enviarArca');
        Route::post('{venta}/cobranzas', [VentaController::class, 'cobranzaStore'])->name('cobranzas.store');
        Route::put('{venta}/cobranzas/{cobro}', [VentaController::class, 'cobranzaUpdate'])->name('cobranzas.update');
        Route::delete('{venta}/cobranzas/{cobro}', [VentaController::class, 'cobranzaDestroy'])->name('cobranzas.destroy');
        Route::get('{venta}/cobranzas/{cobro}/recibo', [VentaController::class, 'reciboCobranza'])->name('cobranzas.recibo');
        Route::post('{venta}/notas', [NotaCreditoDebitoController::class, 'store'])->name('notas.store');
        Route::get('notas/{notaCreditoDebito}/pdf', [NotaCreditoDebitoController::class, 'pdf'])->name('notas.pdf');
        Route::get('{venta}/notas-credito-debito/items-disponibles', [NotaCreditoDebitoController::class, 'itemsDisponiblesVenta'])->name('notas.itemsDisponibles');
        Route::post('{venta}/remitos', [VentaController::class, 'remitoStore'])->name('remitos.store');
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
        });

        // Rutas con {orden} genérico DEBEN ir después de /vinculaciones — si no, "vinculaciones"
        // matchea {orden} primero y la pantalla de vinculaciones nunca se alcanza (bug detectado
        // en el deploy del 28/07/2026).
        Route::get('{orden}/convertir', [MercadoLibreVentaController::class, 'convertir'])->name('convertir');
        Route::post('{orden}/convertir', [MercadoLibreVentaController::class, 'convertirGuardar'])->name('convertirGuardar');
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

    // Egresos (spec 009) — Compras (+ Pagos/Retenciones/NC-ND/Remitos) y Gastos
    Route::middleware('permiso:compras.ver')->prefix('compras')->name('compras.')->group(function () {
        Route::get('/', [CompraController::class, 'index'])->name('index');
        Route::get('data', [CompraController::class, 'data'])->name('data');
        Route::get('nueva', [CompraController::class, 'create'])->name('create');
        Route::post('/', [CompraController::class, 'store'])->name('store');
        Route::get('{compra}/editar', [CompraController::class, 'edit'])->name('edit');
        Route::put('{compra}', [CompraController::class, 'update'])->name('update');
        Route::delete('{compra}', [CompraController::class, 'destroy'])->name('destroy');
        Route::get('{compra}/pdf', [CompraController::class, 'pdf'])->name('pdf');
        Route::post('{compra}/pagos', [CompraController::class, 'pagoStore'])->name('pagos.store');
        Route::delete('{compra}/pagos/{pago}', [CompraController::class, 'pagoDestroy'])->name('pagos.destroy');
        Route::get('{compra}/pagos/{pago}/recibo', [CompraController::class, 'reciboPago'])->name('pagos.recibo');
        Route::post('{compra}/retenciones', [CompraController::class, 'retencionStore'])->name('retenciones.store');
        Route::post('{compra}/notas', [NotaCreditoDebitoController::class, 'storeCompra'])->name('notas.store');
        Route::get('{compra}/notas-credito-debito/items-disponibles', [NotaCreditoDebitoController::class, 'itemsDisponiblesCompra'])->name('notas.itemsDisponibles');
        Route::post('{compra}/remitos', [CompraController::class, 'remitoStore'])->name('remitos.store');
        Route::get('{compra}', [CompraController::class, 'show'])->name('show');
    });

    Route::middleware('permiso:gastos.ver')->prefix('gastos')->name('gastos.')->group(function () {
        Route::get('/', [GastoController::class, 'index'])->name('index');
        Route::get('data', [GastoController::class, 'data'])->name('data');
        Route::post('/', [GastoController::class, 'store'])->name('store');
        Route::put('{gasto}', [GastoController::class, 'update'])->name('update');
        Route::delete('{gasto}', [GastoController::class, 'destroy'])->name('destroy');
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
        });

        // Configuración & Ajustes → Facturación Electrónica (spec 034: ARCA/AFIP)
        Route::middleware('admin')->prefix('arca')->name('arca.')->group(function () {
            Route::get('/', [FacturacionElectronicaController::class, 'index'])->name('index');
            Route::post('certificado', [FacturacionElectronicaController::class, 'guardarCertificado'])->name('certificado.guardar');
            Route::post('puntos-venta', [FacturacionElectronicaController::class, 'guardarPuntoVenta'])->name('puntos-venta.guardar');
            Route::patch('puntos-venta/{puntoVenta}/estado', [FacturacionElectronicaController::class, 'puntoVentaEstado'])->name('puntos-venta.estado');
        });
    });

}); // fin Route::middleware('auth')
