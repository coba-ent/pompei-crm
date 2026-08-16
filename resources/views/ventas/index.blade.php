@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Ventas</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('ventas.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Nueva Venta
                </a>
            </div>
        </div>

        <div class="row mb-3 g-2" id="panel-kpis">
            <div class="col-6" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-primary text-primary">
                                <i class="fas fa-receipt"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Cantidad de Ventas</p>
                                <h4 class="mb-0" id="kpi-cantidad">{{ $kpis['cantidad'] }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-success text-success">
                                <i class="fas fa-dollar-sign"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Cobrado</p>
                                <h4 class="mb-0 text-success" id="kpi-cobrado">$ {{ number_format($kpis['cobrado'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-warning text-warning">
                                <i class="fas fa-hand-holding-dollar"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">A Cobrar</p>
                                <h4 class="mb-0 text-warning" id="kpi-a-cobrar">$ {{ number_format($kpis['a_cobrar'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-danger text-danger">
                                <i class="fas fa-triangle-exclamation"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Vencido</p>
                                <h4 class="mb-0 text-danger" id="kpi-vencido">$ {{ number_format($kpis['vencido'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-primary text-primary">
                                <i class="fas fa-coins"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Total Ventas</p>
                                <h4 class="mb-0" id="kpi-total">$ {{ number_format($kpis['total'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group" style="width:190px">
                            <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="input-group" style="width:190px">
                            <input type="text" id="filtro-rango-vencimiento" class="form-control" placeholder="Vencimiento">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-vencimiento" title="Quitar filtro de Vencimiento"><i class="fas fa-times"></i></button>
                        </div>
                        <span id="dt-buttons-ventas"></span>
                    </div>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Id</label>
                                <input type="text" id="filtro-id" class="form-control" placeholder="Buscar por número de ID">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-cliente" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Cobro</label>
                                <select id="filtro-estado-cobro" class="form-select" multiple>
                                    <option value="sin_cobrar">Sin Cobrar</option>
                                    <option value="parcial">Parcial</option>
                                    <option value="cobrada">Cobrada</option>
                                    <option value="vencido">Vencido</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoría de Venta</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categoriasVenta as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Estado de Factura</label>
                                <select id="filtro-estado-factura" class="form-select" multiple>
                                    <option value="sin_emitir">Sin Emitir</option>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="aprobado">Aprobado</option>
                                    <option value="rechazado">Rechazado</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo y N° de Factura</label>
                                <input type="text" id="filtro-factura" class="form-control" placeholder="Buscar por tipo y número">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Etiqueta</label>
                                <select id="filtro-etiqueta" class="form-select" multiple>
                                    @foreach ($etiquetas as $e)
                                        <option value="{{ $e->id }}">{{ $e->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Vendedor</label>
                                <select id="filtro-vendedor" class="form-select" multiple>
                                    @foreach ($vendedores as $v)
                                        <option value="{{ $v->id }}">{{ $v->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Remitos</label>
                                <select id="filtro-remitos" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="1">Con Remito</option>
                                    <option value="0">Sin Remito</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo y N° de Remito</label>
                                <input type="text" id="filtro-remito-buscar" class="form-control" placeholder="Buscar por tipo y número">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" title="Transportista: no disponible en este CRM (sin tabla/columna propia — ver documentacion_principal_crm.md §5)">Transportista</label>
                                <select class="form-select" disabled>
                                    <option>Todos (no disponible)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Medio de Cobro</label>
                                <select id="filtro-medio-cobro" class="form-select" multiple>
                                    @foreach ($cuentasTesoreria as $ct)
                                        <option value="{{ $ct->id }}">{{ $ct->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select" multiple>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nota Cliente</label>
                                <input type="text" id="filtro-nota-cliente" class="form-control" placeholder="Todos">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nota Interna</label>
                                <input type="text" id="filtro-nota-interna" class="form-control" placeholder="Todos">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Depósito</label>
                                <select id="filtro-deposito" class="form-select" multiple>
                                    @foreach ($depositos as $d)
                                        <option value="{{ $d->id }}">{{ $d->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Creada Desde</label>
                                <select id="filtro-creada-desde" class="form-select">
                                    <option value="">Todas</option>
                                    <option value="venta">Venta</option>
                                    <option value="presupuesto">Presupuesto</option>
                                    <option value="mercadolibre">MercadoLibre</option>
                                    <option value="tiendanube">Tiendanube</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Servicio Desde</label>
                                <input type="text" id="filtro-servicio-desde" class="form-control" data-fecha-ar>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Servicio Hasta</label>
                                <input type="text" id="filtro-servicio-hasta" class="form-control" data-fecha-ar>
                            </div>

                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-light" id="btn-limpiar-filtros">Limpiar</button>
                                <button type="button" class="btn btn-primary" id="btn-aplicar-filtros">
                                    <i class="fas fa-search me-1"></i> Buscar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-ventas" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Id</th>
                                <th>Creada Desde</th>
                                <th>Creado</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Cliente</th>
                                <th>Productos</th>
                                <th>Categoría</th>
                                <th>Subtotal sin Descuento</th>
                                <th>Descuento</th>
                                <th>Subtotal con Descuento</th>
                                <th>Total</th>
                                <th>A Cobrar</th>
                                <th>Cobrado</th>
                                <th>Etiquetas</th>
                                <th>Medio de Cobro</th>
                                <th>Nota Cliente</th>
                                <th>Nota Interna</th>
                                <th>Lista de Precios</th>
                                <th>Vendedor</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-eliminar-venta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar venta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar esta venta? Se revierte el cobro en Tesorería. Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@include('ventas._modales_arca')
@endsection

@section('local-js')
<script>
    window.VentasConfig = {
        rutas: {
            data: "{{ route('ventas.data') }}",
            kpis: "{{ route('ventas.kpis') }}",
            show: "{{ url('ventas') }}",
            pdf: "{{ url('ventas') }}",
            ticket: "{{ url('ventas') }}",
            clientesOpciones: "{{ route('clientes.opciones') }}",
        },
    };
</script>
@vite(['resources/js/ventas.js'])
@endsection
