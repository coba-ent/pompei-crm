@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Proveedores</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('importacion.index', 'proveedores') }}" class="btn btn-outline-primary me-1">
                    <i class="fas fa-file-import me-1"></i> Importar datos
                </a>
                <button type="button" class="btn btn-primary" id="btn-nuevo-proveedor">
                    <i class="fas fa-plus me-1"></i> Nuevo Proveedor
                </button>
            </div>
        </div>

        {{-- Cards informativas --}}
        <div class="row">
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Total de proveedores</h6>
                            <span class="icon-box icon-box-sm bg-primary-light rounded">
                                <i class="fas fa-truck text-primary"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-total">{{ number_format($stats['total'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Cartera completa</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Proveedores activos</h6>
                            <span class="icon-box icon-box-sm bg-success-light rounded">
                                <i class="fas fa-check-circle text-success"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-activos">{{ number_format($stats['activos'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Disponibles para operar</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Nuevos este mes</h6>
                            <span class="icon-box icon-box-sm bg-warning-light rounded">
                                <i class="fas fa-user-plus text-warning"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-nuevos">{{ number_format($stats['nuevos_mes'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Altas del mes en curso</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

                {{-- Buscador (estilo Contagram: busca por cualquier dato del proveedor) --}}
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-lg-8">
                        <div class="input-group">
                            <input type="text" id="buscador-proveedores" class="form-control"
                                   placeholder="Busca Proveedores utilizando alguno de sus datos">
                            <button type="button" class="btn btn-primary" id="btn-buscar-proveedores">
                                <i class="fas fa-search me-1"></i> Buscar
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4 d-flex justify-content-lg-end align-items-center gap-2">
                        {{-- Contenedor donde se inyecta el botón ColVis ("Columnas") --}}
                        <span id="proveedores-colvis"></span>
                        <button type="button" class="btn btn-outline-primary" id="btn-exportar">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-proveedores" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Proveedor</th>
                                <th>Nombre</th>
                                <th>Apellido</th>
                                <th>Mail</th>
                                <th>Teléfono</th>
                                <th>Teléfono Celular</th>
                                <th>Domicilio</th>
                                <th>Localidad</th>
                                <th>Provincia</th>
                                <th>DNI</th>
                                <th>CUIT</th>
                                <th>Condición de IVA</th>
                                <th>Nota</th>
                                <th>Página Web</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>
</div>

@include('proveedores._modal_form')
@endsection

@section('local-js')
<script>
    window.ProveedoresConfig = {
        rutas: {
            data: "{{ route('proveedores.data') }}",
            stats: "{{ route('proveedores.stats') }}",
            export: "{{ route('proveedores.export') }}",
            store: "{{ route('proveedores.store') }}",
            show: "{{ url('proveedores') }}",
            localidades: "{{ route('geo.localidades') }}",
            verificarDocumento: "{{ route('proveedores.verificar-documento') }}",
        },
    };
</script>
@vite(['resources/js/proveedores.js'])
@endsection
