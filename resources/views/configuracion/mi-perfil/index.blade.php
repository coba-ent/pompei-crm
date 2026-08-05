@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Empresa</h4>
                <p class="text-muted mb-0">
                    Datos fiscales del negocio emisor, usados como encabezado en los comprobantes imprimibles del CRM.
                </p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Datos de la empresa</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-mi-perfil">
                    <i class="fas fa-pencil-alt me-1"></i> Editar
                </button>
            </div>
            <div class="card-body">
                @if ($datosEmpresa)
                    <div class="row g-3">
                        <div class="col-md-3">
                            @if ($datosEmpresa->ruta_logo)
                                <img src="{{ asset('storage/'.$datosEmpresa->ruta_logo) }}" alt="Logo" style="max-width:100px; max-height:100px;">
                            @else
                                <span class="text-muted">Sin logo</span>
                            @endif
                        </div>
                        <div class="col-md-9">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label text-muted mb-1">Razón Social</label><div class="fw-bold">{{ $datosEmpresa->razon_social ?: '-' }}</div></div>
                                <div class="col-md-4"><label class="form-label text-muted mb-1">CUIT</label><div class="fw-bold">{{ $datosEmpresa->cuit ?: '-' }}</div></div>
                                <div class="col-md-4"><label class="form-label text-muted mb-1">Domicilio Fiscal</label><div class="fw-bold">{{ $datosEmpresa->domicilio_fiscal ?: '-' }}</div></div>
                                <div class="col-md-4"><label class="form-label text-muted mb-1">Condición de IVA</label><div class="fw-bold">{{ $datosEmpresa->condicion_iva ?: '-' }}</div></div>
                                <div class="col-md-4"><label class="form-label text-muted mb-1">Ingresos Brutos</label><div class="fw-bold">{{ $datosEmpresa->ingresos_brutos ?: '-' }}</div></div>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="text-muted mb-0">Todavía no se cargaron los datos de Empresa — los PDFs de comprobantes se generan sin encabezado del emisor.</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Usuarios</h6>
                <div>
                    <span id="dt-buttons-usuarios"></span>
                    <a href="{{ route('configuracion.roles.index') }}" class="btn btn-outline-secondary me-1">
                        <i class="fas fa-user-shield me-1"></i> Roles y Permisos
                    </a>
                    <button type="button" class="btn btn-primary" id="btn-nuevo-usuario">
                        <i class="fas fa-plus me-1"></i> Nuevo Usuario
                    </button>
                </div>
            </div>
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

{{-- Modal: Mi Perfil --}}
<div class="modal fade" id="modal-mi-perfil" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-mi-perfil" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Editar Empresa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Razón Social</label>
                        <input type="text" class="form-control" name="razon_social" value="{{ $datosEmpresa->razon_social ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CUIT</label>
                        <input type="text" class="form-control" name="cuit" maxlength="11" placeholder="20111111112" value="{{ $datosEmpresa->cuit ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Domicilio Fiscal</label>
                        <input type="text" class="form-control" name="domicilio_fiscal" value="{{ $datosEmpresa->domicilio_fiscal ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Condición de IVA</label>
                        <select class="form-select" name="condicion_iva" id="select-condicion-iva-empresa">
                            <option value="">Seleccione una condición de IVA</option>
                            @foreach ($condicionesIva as $condicion)
                                <option value="{{ $condicion->nombre }}" {{ ($datosEmpresa->condicion_iva ?? '') === $condicion->nombre ? 'selected' : '' }}>
                                    {{ $condicion->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ingresos Brutos</label>
                        <input type="text" class="form-control" name="ingresos_brutos" value="{{ $datosEmpresa->ingresos_brutos ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png,.webp">
                        <div class="form-text">JPG, PNG o WEBP, hasta 2 MB.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@section('local-js')
<script>
    window.MiPerfilConfig = {
        rutas: { guardar: @json(route('configuracion.mi-perfil.guardar')) },
    };
    window.UsuariosConfig = {
        rutas: {
            data: @json(route('configuracion.usuarios.data')),
            store: @json(route('configuracion.usuarios.store')),
            show: @json(url('configuracion/usuarios')),
        },
        roles: @json($roles->map(fn ($r) => ['id' => $r->id, 'nombre' => $r->nombre])),
    };
</script>
@vite(['resources/js/mi-perfil.js', 'resources/js/configuracion-usuarios.js'])
@if (request()->boolean('crear'))
<script>
    (function () {
        var intentos = 0;
        var intervalo = setInterval(function () {
            intentos++;
            var btn = document.getElementById('btn-nuevo-usuario');
            var eventos = (window.jQuery && jQuery._data && btn) ? jQuery._data(btn, 'events') : null;
            if ((eventos && eventos.click) || intentos >= 100) {
                clearInterval(intervalo);
                if (btn) { btn.click(); }
            }
        }, 100);
    })();
</script>
@endif
@endsection
@endsection
