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

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <span id="dt-buttons-ventas"></span>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">N° de Comprobante</label>
                                <input type="text" id="filtro-buscar" class="form-control" placeholder="Buscar por N°">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-cliente" class="form-select" multiple></select>
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
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Cliente</th>
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
<div class="modal fade" id="modal-confirmar-arca" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Enviar a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Enviar esta Venta a ARCA para solicitar el CAE? Es una acción real ante un ente fiscal.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btn-confirmar-arca">
                    <i class="fas fa-paper-plane me-1"></i>Enviar a ARCA
                </button>
            </div>
        </div>
    </div>
</div>
{{-- Resultado real de un envío a ARCA (spec 040, FR-007) — modal persistente, no toast --}}
<div class="modal fade" id="modal-resultado-arca" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-resultado-arca-titulo">Resultado del envío a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modal-resultado-arca-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.VentasConfig = {
        rutas: {
            data: "{{ route('ventas.data') }}",
            show: "{{ url('ventas') }}",
            pdf: "{{ url('ventas') }}",
            ticket: "{{ url('ventas') }}",
            clientesOpciones: "{{ route('clientes.opciones') }}",
        },
    };
</script>
@vite(['resources/js/ventas.js'])
@endsection
