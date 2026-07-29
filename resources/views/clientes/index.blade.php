@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Clientes</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('importacion.index', 'clientes') }}" class="btn btn-outline-primary me-1">
                    <i class="fas fa-file-import me-1"></i> Importar datos
                </a>
                <button type="button" class="btn btn-primary" id="btn-nuevo-cliente">
                    <i class="fas fa-plus me-1"></i> Nuevo Cliente
                </button>
            </div>
        </div>

        {{-- Cards informativas --}}
        <div class="row">
            <div class="col-xl-3 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Total de clientes</h6>
                            <span class="icon-box icon-box-sm bg-primary-light rounded">
                                <i class="fas fa-users text-primary"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-total">{{ number_format($stats['total'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Cartera completa</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Clientes activos</h6>
                            <span class="icon-box icon-box-sm bg-success-light rounded">
                                <i class="fas fa-user-check text-success"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-activos">{{ number_format($stats['activos'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Disponibles para operar</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Aptos para facturar</h6>
                            <span class="icon-box icon-box-sm bg-info-light rounded">
                                <i class="fas fa-file-invoice text-info"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-aptos">{{ number_format($stats['aptos'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2">
                        <span class="fs-13 text-muted">Con condición de IVA válida</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
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

                {{-- Buscador (estilo Contagram: busca por cualquier dato del cliente) --}}
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-lg-8">
                        <div class="input-group">
                            <input type="text" id="buscador-clientes" class="form-control"
                                   placeholder="Busca Clientes utilizando alguno de sus datos">
                            <button type="button" class="btn btn-primary" id="btn-buscar-clientes">
                                <i class="fas fa-search me-1"></i> Buscar
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4 d-flex justify-content-lg-end align-items-center gap-2">
                        {{-- Contenedor donde se inyecta el botón ColVis ("Columnas") --}}
                        <span id="clientes-colvis"></span>
                        <button type="button" class="btn btn-outline-primary" id="btn-exportar">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-clientes" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Cliente</th>
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
                                <th>Usuario de Mercado Libre</th>
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

@include('clientes._modal_form')
@endsection

@section('local-js')
<script>
    window.ClientesConfig = {
        rutas: {
            data: "{{ route('clientes.data') }}",
            stats: "{{ route('clientes.stats') }}",
            export: "{{ route('clientes.export') }}",
            store: "{{ route('clientes.store') }}",
            show: "{{ url('clientes') }}",
            localidades: "{{ route('geo.localidades') }}",
            verificarDocumento: "{{ route('clientes.verificar-documento') }}",
        },
    };
</script>
@vite(['resources/js/clientes.js'])
@endsection
