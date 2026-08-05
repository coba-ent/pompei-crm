@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Roles y Permisos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <span id="dt-buttons-roles"></span>
                <a href="{{ route('configuracion.mi-perfil.index') }}" class="btn btn-outline-secondary me-1">
                    <i class="fas fa-users me-1"></i> Usuarios
                </a>
                <button type="button" class="btn btn-primary" id="btn-nuevo-rol">
                    <i class="fas fa-plus me-1"></i> Nuevo Rol
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tabla-roles" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th class="dt-acciones-caret"></th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th># Permisos</th>
                                <th># Usuarios</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

@include('configuracion.roles._modal_form')
@endsection

@section('local-js')
<script>
    window.RolesConfig = {
        rutas: {
            data: @json(route('configuracion.roles.data')),
            store: @json(route('configuracion.roles.store')),
            show: @json(url('configuracion/roles')),
            permisos: @json(route('configuracion.roles.permisos')),
        },
    };
</script>
@vite(['resources/js/configuracion-roles.js'])
@endsection
