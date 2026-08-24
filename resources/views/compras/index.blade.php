@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Compras</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('compras.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Nueva Compra
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
                                <p class="mb-1">Cantidad de Compras</p>
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
                                <p class="mb-1">Pagado</p>
                                <h4 class="mb-0" id="kpi-pagado">$ {{ number_format($kpis['pagado'], 2, ',', '.') }}</h4>
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
                                <p class="mb-1">A Pagar</p>
                                <h4 class="mb-0" id="kpi-a-pagar">$ {{ number_format($kpis['a_pagar'], 2, ',', '.') }}</h4>
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
                                <p class="mb-1">Total Compras</p>
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
                        <span id="dt-buttons-compras"></span>
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
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-proveedor" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoría de Compra</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categoriasCompra as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Pago</label>
                                <select id="filtro-estado-pago" class="form-select" multiple>
                                    <option value="a_pagar">A Pagar</option>
                                    <option value="parcial">Parcial</option>
                                    <option value="pagado">Pagado</option>
                                    <option value="vencido">Vencido</option>
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
                                <label class="form-label">Facturado</label>
                                <select id="filtro-facturado" class="form-select" multiple>
                                    <option value="1">Sí</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Medio de pago</label>
                                <select id="filtro-medio-pago" class="form-select" multiple>
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
                    <table id="tabla-compras" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Id</th>
                                <th>Creado</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Proveedor</th>
                                <th>Nro. Factura</th>
                                <th>Categoría</th>
                                <th>Subtotal sin Descuento</th>
                                <th>Descuento</th>
                                <th>Subtotal con Descuento</th>
                                <th>Total Compra</th>
                                <th>Pagado</th>
                                <th>A Pagar</th>
                                <th>Medio de Pago</th>
                                <th>Etiquetas</th>
                                <th>CUIT</th>
                                <th>Teléfono</th>
                                <th>Mail</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-eliminar-compra" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar compra</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar esta compra? Se revierten los pagos en Tesorería. Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.ComprasConfig = {
        rutas: {
            data: "{{ route('compras.data') }}",
            kpis: "{{ route('compras.kpis') }}",
            show: "{{ url('compras') }}",
            pdf: "{{ url('compras') }}",
            proveedoresOpciones: "{{ route('proveedores.opciones') }}",
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/compras.js'])
@endsection
