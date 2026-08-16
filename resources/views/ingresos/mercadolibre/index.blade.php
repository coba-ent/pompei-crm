@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Mercado Libre</h4>
                <p class="text-muted mb-0">Órdenes de venta sincronizadas desde tu cuenta de Mercado Libre.</p>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                @if (Route::has('ingresos.mercadolibre.vinculaciones.index'))
                    <a href="{{ route('ingresos.mercadolibre.vinculaciones.index') }}" class="btn btn-outline-primary me-1">
                        <i class="fas fa-link me-1"></i> Vinculaciones
                    </a>
                @endif
                <button type="button" class="btn btn-outline-primary me-1" id="btn-sincronizar-stock-ml">
                    <i class="fas fa-boxes-stacked me-1"></i> Sincronizar stock ahora
                </button>
                {{-- "Sincronizar precios ahora" vive en Productos (spec 016, corrección de UX): --}}
                <button type="button" class="btn btn-outline-primary me-1" id="btn-sincronizar-ml">
                    <i class="fas fa-sync-alt me-1"></i> Sincronizar ahora
                </button>
                <button type="button" class="btn btn-primary" id="btn-transformar-todas-en-venta-ml">
                    <i class="fas fa-file-invoice-dollar me-1"></i> Transformar todas en Venta
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros-ml">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <span id="dt-buttons-ml-ordenes"></span>
                </div>

                <div class="collapse mb-3" id="panel-filtros-ml">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Estado en Mercado Libre</label>
                                <select id="filtro-estado-orden" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="pendiente">Pendiente de pago</option>
                                    <option value="pagada">Pagada</option>
                                    <option value="cancelada">Cancelada</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado de conversión</label>
                                <select id="filtro-estado-conversion" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="pendiente_pago">Pendiente de pago</option>
                                    <option value="lista">Lista para convertir</option>
                                    <option value="requiere_atencion">Requiere atención</option>
                                    <option value="convertida">Convertida</option>
                                    <option value="cancelada">Cancelada</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Desde</label>
                                <input type="text" id="filtro-desde" class="form-control" data-fecha-ar>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hasta</label>
                                <input type="text" id="filtro-hasta" class="form-control" data-fecha-ar>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-light" id="btn-limpiar-filtros-ml">Limpiar</button>
                                <button type="button" class="btn btn-primary" id="btn-aplicar-filtros-ml">
                                    <i class="fas fa-search me-1"></i> Buscar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-ml-ordenes" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Acciones</th>
                                <th>Orden</th>
                                <th>Etiquetas</th>
                                <th>Fecha</th>
                                <th>Comprador</th>
                                <th>Productos</th>
                                <th>Total</th>
                                <th>Estado en ML</th>
                                <th>Estado de conversión</th>
                                <th>Motivo</th>
                                <th>Venta</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-detalle-orden-ml" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalle de la orden</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modal-detalle-orden-ml-body">
                Cargando…
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-descartar-aviso-ml" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Descartar aviso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">¿Descartar este aviso? La Venta queda vigente tal cual está, sin ningún cambio en su total, cobro ni stock.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btn-confirmar-descartar-aviso-ml">Descartar aviso</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-resultado-transformar-venta-ml" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Resultado de la transformación en Venta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="resultado-transformar-venta-ml-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.MercadoLibreVentasConfig = {
        rutas: {
            datatable: @json(route('ingresos.mercadolibre.datatable')),
            sincronizar: @json(route('ingresos.mercadolibre.sincronizar')),
            sincronizarStock: @json(route('ingresos.mercadolibre.sincronizarStock')),
            transformarTodasEnVenta: @json(route('ingresos.mercadolibre.transformarTodasEnVenta')),
            show: @json(url('ingresos/mercadolibre')),
            descartarAviso: @json(url('ingresos/mercadolibre')),
            ventaShow: @json(url('ventas')),
            vinculaciones: @json(Route::has('ingresos.mercadolibre.vinculaciones.index') ? route('ingresos.mercadolibre.vinculaciones.index') : null),
        },
    };
</script>
@vite(['resources/js/mercadolibre-ventas.js'])
@endsection
