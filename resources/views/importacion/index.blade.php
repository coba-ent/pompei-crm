@extends('layouts.default')

@php
    $titulos = ['clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'productos' => 'Productos & Servicios'];
    $solapas = ['clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'productos' => 'Productos & Servicios'];
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12">
                <h4 class="mb-0 text-primary fw-bold">Importar Datos</h4>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <ul class="nav nav-tabs mb-3">
            @foreach ($solapas as $clave => $etiqueta)
                <li class="nav-item">
                    <a class="nav-link @if($entidad === $clave) active @endif" href="{{ route('importacion.index', $clave) }}">{{ $etiqueta }}</a>
                </li>
            @endforeach
        </ul>

        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <p class="mb-3">Seleccioná un archivo con tus {{ $titulos[$entidad] }} para importar.</p>
                        <p class="text-muted small mb-4">Formatos permitidos: .xls, .xlsx, .csv — tamaño máximo 10MB.</p>

                        <form action="{{ route('importacion.subir', $entidad) }}" method="POST" enctype="multipart/form-data" id="form-importacion-subir">
                            @csrf
                            <div class="mb-3 col-md-8 mx-auto text-start">
                                <input type="file" name="archivo" class="form-control" accept=".xls,.xlsx,.csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary" id="btn-importacion-subir">
                                <i class="fas fa-upload me-1"></i> Seleccionar Archivo
                            </button>
                        </form>
                    </div>
                </div>

                <div class="mt-3">
                    <a href="{{ route($entidad.'.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Ver mis {{ $titulos[$entidad] }}
                    </a>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold">Acerca de la importación de {{ $titulos[$entidad] }}</h6>
                        <p class="text-muted small">
                            Subí un archivo Excel/CSV con tu cartera de {{ strtolower($titulos[$entidad]) }} existente.
                            En el paso siguiente vas a poder mapear cada columna del archivo a un campo del sistema,
                            o crear un campo personalizado para las columnas que no tengan un campo fijo
                            correspondiente.
                        </p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold">Notas Técnicas</h6>
                        <ul class="text-muted small mb-0 ps-3">
                            <li>Tamaño máximo del archivo: 10MB.</li>
                            <li>Cada fila se valida igual que en el alta manual; las filas inválidas se
                                reportan en el resumen sin bloquear al resto del archivo.</li>
                            @if ($entidad === 'productos')
                                <li>Se recomienda importar primero <strong>Proveedores</strong> y luego
                                    <strong>Productos &amp; Servicios</strong> si querés que cada producto quede
                                    asociado a su proveedor por defecto.</li>
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    document.getElementById('form-importacion-subir').addEventListener('submit', function () {
        window.AppBtn.loading('#btn-importacion-subir', true);
    });
</script>
@endsection
