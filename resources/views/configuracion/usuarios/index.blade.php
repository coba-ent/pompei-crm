@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Usuarios y Permisos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                @can('configuracion.roles')
                    <a href="{{ route('configuracion.roles.index') }}" class="btn btn-outline-secondary me-1">
                        <i class="fas fa-user-shield me-1"></i> Roles y Permisos
                    </a>
                @endcan
                <button type="button" class="btn btn-primary" id="btn-nuevo-usuario">
                    <i class="fas fa-plus me-1"></i> Nuevo Usuario
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tabla-usuarios" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Roles</th>
                                <th>Estado</th>
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

@include('configuracion.usuarios._modal_form')
@endsection

@section('local-js')
<script>
    window.UsuariosConfig = {
        rutas: {
            data: @json(route('configuracion.usuarios.data')),
            store: @json(route('configuracion.usuarios.store')),
            show: @json(url('configuracion/usuarios')),
        },
        roles: @json($roles->map(fn ($r) => ['id' => $r->id, 'nombre' => $r->nombre])),
    };
</script>
@vite(['resources/js/configuracion-usuarios.js'])
@endsection
