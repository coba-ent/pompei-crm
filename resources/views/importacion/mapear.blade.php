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

        <form action="{{ route('importacion.confirmar-lote', $entidad) }}" method="POST" id="form-mapeo">
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

<div class="modal fade" id="modal-importando" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
                <h6 class="fw-bold mb-1">Importando datos…</h6>
                <p class="text-muted small mb-3" id="modal-importando-detalle">Preparando la importación…</p>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar" id="modal-importando-barra" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <p class="text-muted small mt-2 mb-0">No cierres ni recargues esta ventana.</p>
            </div>
        </div>
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

    document.getElementById('form-cancelar').addEventListener('submit', function () {
        window.AppBtn.loading('#btn-cancelar-importacion', true);
    });

    document.getElementById('form-mapeo').addEventListener('submit', function (evento) {
        evento.preventDefault();

        var form = evento.target;
        document.getElementById('btn-confirmar-importacion').disabled = true;
        var url = form.action;
        var token = form.querySelector('input[name="_token"]').value;
        var mapeoFijo = new FormData(form); // mapeo[]/personalizados[] no cambian entre tandas

        var modalEl = document.getElementById('modal-importando');
        var modal = new bootstrap.Modal(modalEl);
        var barra = document.getElementById('modal-importando-barra');
        var detalle = document.getElementById('modal-importando-detalle');
        modal.show();

        function procesarTanda(offset) {
            var datos = new FormData();
            mapeoFijo.forEach(function (valor, clave) { datos.append(clave, valor); });
            datos.set('_token', token);
            datos.set('offset', offset);

            fetch(url, {
                method: 'POST',
                body: datos,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (respuesta) {
                    if (! respuesta.ok) {
                        return respuesta.json().then(function (cuerpo) {
                            throw new Error(cuerpo.error || 'Error al importar.');
                        });
                    }
                    return respuesta.json();
                })
                .then(function (resultado) {
                    var porcentaje = resultado.total > 0 ? Math.round((resultado.procesadas / resultado.total) * 100) : 100;
                    barra.style.width = porcentaje + '%';
                    barra.setAttribute('aria-valuenow', porcentaje);
                    detalle.textContent = 'Procesadas ' + resultado.procesadas + ' de ' + resultado.total + ' filas…';

                    if (resultado.terminado) {
                        window.location.href = resultado.resumen_url;
                        return;
                    }

                    procesarTanda(resultado.procesadas);
                })
                .catch(function (error) {
                    modal.hide();
                    document.getElementById('btn-confirmar-importacion').disabled = false;
                    window.toastr && window.toastr.error ? window.toastr.error(error.message) : alert(error.message);
                });
        }

        procesarTanda(0);
    });
</script>
@endsection
