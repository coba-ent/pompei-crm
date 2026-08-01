@extends('layouts.default')

@php
    $titulos = ['clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'productos' => 'Productos & Servicios'];
    $permiteCampoPersonalizado = $entidad !== 'productos';
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12">
                <h4 class="mb-0 text-primary fw-bold">Importar Datos — {{ $titulos[$entidad] }}</h4>
                <p class="text-muted mb-0">Vista previa del archivo y mapeo de columnas.</p>
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

        <form action="{{ route('importacion.confirmar', $entidad) }}" method="POST" id="form-mapeo">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Mapeo de columnas</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead>
                                <tr>
                                    @foreach ($columnas as $indice => $columna)
                                        <th>{{ $columna !== '' && $columna !== null ? $columna : 'Columna '.($indice + 1) }}</th>
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach ($columnas as $indice => $columna)
                                        <th>
                                            <select name="mapeo[{{ $indice }}]" class="form-select form-select-sm js-select-mapeo" data-indice="{{ $indice }}">
                                                <option value="" @selected(! isset($sugerencias[$indice]))>No importar</option>
                                                @foreach ($definicion as $clave => $def)
                                                    <option value="{{ $clave }}" @selected(($sugerencias[$indice] ?? null) === $clave)>{{ $def['etiqueta'] }}@if($def['obligatorio']) *@endif</option>
                                                @endforeach
                                                @if ($permiteCampoPersonalizado)
                                                    <option value="personalizado">Campo personalizado</option>
                                                @endif
                                            </select>
                                            @if ($permiteCampoPersonalizado)
                                                <input type="text" name="personalizados[{{ $indice }}]" class="form-control form-control-sm mt-1 d-none js-input-personalizado" data-indice="{{ $indice }}" placeholder="Nombre del campo">
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($preview as $fila)
                                    <tr>
                                        @foreach ($columnas as $indice => $columna)
                                            <td>{{ $fila[$indice] ?? '' }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($columnas) }}" class="text-muted text-center">Sin filas de datos para previsualizar.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mb-0">* campo obligatorio.</p>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btn-confirmar-importacion">
                    <i class="fas fa-check me-1"></i> Confirmar importación
                </button>
            </div>
        </form>

        <form action="{{ route('importacion.cancelar', $entidad) }}" method="POST" id="form-cancelar" class="d-inline mt-2">
            @csrf
            <button type="submit" class="btn btn-outline-secondary mt-2" id="btn-cancelar-importacion">
                <i class="fas fa-times me-1"></i> Cancelar
            </button>
        </form>

    </div>
</div>
@endsection

@section('local-js')
<script>
    document.querySelectorAll('.js-select-mapeo').forEach(function (select) {
        var indice = select.dataset.indice;
        var input = document.querySelector('.js-input-personalizado[data-indice="' + indice + '"]');
        if (! input) {
            return;
        }
        var actualizar = function () {
            input.classList.toggle('d-none', select.value !== 'personalizado');
        };
        select.addEventListener('change', actualizar);
        actualizar();
    });

    document.getElementById('form-mapeo').addEventListener('submit', function () {
        window.AppBtn.loading('#btn-confirmar-importacion', true);
    });
    document.getElementById('form-cancelar').addEventListener('submit', function () {
        window.AppBtn.loading('#btn-cancelar-importacion', true);
    });
</script>
@endsection
