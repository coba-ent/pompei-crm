@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Gastos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-primary" id="btn-nuevo-gasto">
                    <i class="fas fa-plus me-1"></i> Nuevo Gasto
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center justify-content-between">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                            <i class="fas fa-filter me-1"></i> Filtros
                        </button>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-columns me-1"></i> Columnas
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end p-2" id="selector-columnas" style="min-width:220px;"></ul>
                    </div>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Descripción</label>
                                <input type="text" id="filtro-buscar" class="form-control">
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
                    <table id="tabla-gastos" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Categoría</th>
                                <th>Descripción</th>
                                <th>Medio de Pago</th>
                                <th>Monto</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

@include('gastos._modal_gasto')
@include('gastos._modal_categoria')

<div class="modal fade" id="modal-eliminar-gasto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar gasto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">¿Seguro que querés eliminar este gasto? Se revierte el movimiento en Tesorería si correspondía.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@php
    $datosCategorias = $categorias->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'categoria_padre_id' => $c->categoria_padre_id]);
    $datosCuentas = $cuentas->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre]);
@endphp
@section('local-js')
<script>
    window.GastosConfig = {
        rutas: {
            data: "{{ route('gastos.data') }}",
            store: "{{ route('gastos.store') }}",
            updateBase: "{{ url('gastos') }}",
            categoriaGastoStore: "{{ route('categorias.gasto.store') }}",
            categoriaGastoSubcategoriaStoreBase: "{{ url('categorias-gasto') }}",
        },
        categorias: @json($datosCategorias),
        cuentas: @json($datosCuentas),
    };
</script>
@vite(['resources/js/gastos.js'])
@endsection
