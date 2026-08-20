@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Presupuestos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('presupuestos.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Nuevo Presupuesto
                </a>
            </div>
        </div>

        {{-- Barra de 5 KPIs (informe §2.1) --}}
        <div class="row mb-3 g-2" id="panel-kpis">
            <div class="col-6 col-md-2-4" style="flex:1 1 20%; max-width:20%;">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-primary text-primary">
                                <i class="fas fa-check-double"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Ventas</p>
                                <h4 class="mb-0" id="kpi-ventas">{{ $kpis['ventas'] }}</h4>
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
                                <p class="mb-1">Vencidos/Rechazados</p>
                                <h4 class="mb-0 text-danger" id="kpi-vencidos">{{ $kpis['vencidos_rechazados'] }}</h4>
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
                                <i class="fas fa-clock"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Pendientes</p>
                                <h4 class="mb-0 text-warning" id="kpi-pendientes">{{ $kpis['pendientes'] }}</h4>
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
                                <i class="fas fa-thumbs-up"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Aceptados</p>
                                <h4 class="mb-0 text-success" id="kpi-aceptados">{{ $kpis['aceptados'] }}</h4>
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
                                <p class="mb-1">Total Posibles</p>
                                <h4 class="mb-0" id="kpi-total-posibles">$ {{ number_format($kpis['total_posibles'], 2, ',', '.') }}</h4>
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
                        <span id="dt-buttons-presupuestos"></span>
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
                                <label class="form-label">Producto/Servicio</label>
                                <select id="filtro-producto" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-cliente" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Presupuesto</label>
                                <select id="filtro-estado" class="form-select" multiple>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="vencido">Vencido</option>
                                    <option value="rechazado">Rechazado</option>
                                    <option value="aceptado">Aceptado</option>
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
                                <label class="form-label">N° de Presupuesto</label>
                                <input type="text" id="filtro-buscar" class="form-control" placeholder="Buscar por número de presupuesto">
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
                                <label class="form-label">Formas de Pago</label>
                                <input type="text" id="filtro-formas-pago" class="form-control" placeholder="Buscar por Formas de Pago">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Métodos de Envío</label>
                                <input type="text" id="filtro-metodos-envio" class="form-control" placeholder="Buscar por Métodos de Envío">
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
                                <label class="form-label">Nota para el Cliente</label>
                                <input type="text" id="filtro-nota-cliente" class="form-control" placeholder="Buscar por Nota para el Cliente">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Nota Interna</label>
                                <input type="text" id="filtro-nota-interna" class="form-control" placeholder="Buscar por Nota Interna">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Servicio Desde</label>
                                <input type="text" id="filtro-servicio-desde" class="form-control" data-fecha-ar>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hasta Servicio</label>
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
                    <table id="tabla-presupuestos" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Id</th>
                                <th>Creado</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Cliente</th>
                                <th>Categoría</th>
                                <th>Nro. Presupuesto</th>
                                <th>Subtotal sin Descuento</th>
                                <th>Descuento</th>
                                <th>Subtotal con Descuento</th>
                                <th>Total</th>
                                <th>Nota Cliente</th>
                                <th>Nota Interna</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- Modal de confirmación de eliminación --}}
<div class="modal fade" id="modal-eliminar-presupuesto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar presupuesto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">¿Seguro que querés eliminar este presupuesto? Esta acción no se puede deshacer.</div>
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
    window.PresupuestosConfig = {
        rutas: {
            data: "{{ route('presupuestos.data') }}",
            kpis: "{{ route('presupuestos.kpis') }}",
            show: "{{ url('presupuestos') }}",
            estado: "{{ url('presupuestos') }}",
            crearVenta: "{{ url('presupuestos') }}",
            pdf: "{{ url('presupuestos') }}",
            clientesOpciones: "{{ route('clientes.opciones') }}",
            productosOpciones: "{{ route('productos.opciones') }}",
        },
    };
</script>
@vite(['resources/js/presupuestos.js'])
@endsection
