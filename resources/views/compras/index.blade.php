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

        <div class="row g-3 mb-4">
            <div class="col-6 col-md">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small">Cantidad de Compras</div>
                        <div class="h5 mb-0">{{ $kpis['cantidad'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small">Pagado</div>
                        <div class="h5 mb-0">$ {{ number_format($kpis['pagado'], 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small">A Pagar</div>
                        <div class="h5 mb-0">$ {{ number_format($kpis['a_pagar'], 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small">Vencido</div>
                        <div class="h5 mb-0 text-danger">$ {{ number_format($kpis['vencido'], 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total Compras</div>
                        <div class="h5 mb-0">$ {{ number_format($kpis['total'], 2, ',', '.') }}</div>
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
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">N° de Comprobante</label>
                                <input type="text" id="filtro-buscar" class="form-control" placeholder="Buscar por N°">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-proveedor" class="form-select"><option value="">Todos</option></select>
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
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Proveedor</th>
                                <th>Categoría</th>
                                <th>Subtotal sin Descuento</th>
                                <th>Descuento</th>
                                <th>Subtotal con Descuento</th>
                                <th>Total Compra</th>
                                <th>Pagado</th>
                                <th>A Pagar</th>
                                <th>Medio de Pago</th>
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
            show: "{{ url('compras') }}",
            pdf: "{{ url('compras') }}",
            proveedoresOpciones: "{{ route('proveedores.opciones') }}",
        },
    };
</script>
@vite(['resources/js/compras.js'])
@endsection
