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
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">Mapeo de columnas</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-desmapear-todo">
                            <i class="fas fa-eraser me-1"></i> Desmapear todo
                        </button>
                    </div>
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
                                            <select name="mapeo[{{ $indice }}]" class="form-select form-select-sm js-select-mapeo" data-indice="{{ $indice }}" data-container="body" data-live-search="true">
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

            {{-- Spec 082: huella de los encabezados que esta pantalla tiene a la vista. El backend la
                 compara con la del archivo vigente y rechaza el lote si cambió (el usuario subió otro
                 archivo en otra pestaña), en vez de escribir en columnas equivocadas. --}}
            <input type="hidden" name="huella_columnas" value="{{ \App\Http\Controllers\ImportacionController::huellaColumnas($columnas) }}">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btn-confirmar-importacion">
                    <i class="fas fa-check me-1"></i> Confirmar importación
                </button>
                {{-- Spec 082: aparece sólo si una tanda falló tras agotar los reintentos automáticos.
                     Retoma desde el último offset confirmado, sin repetir ni saltear filas. --}}
                <button type="button" class="btn btn-warning d-none" id="btn-reanudar-importacion">
                    <i class="fas fa-redo me-1"></i> Reanudar desde la fila <span id="reanudar-fila">0</span>
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

    document.getElementById('btn-desmapear-todo').addEventListener('click', function () {
        document.querySelectorAll('.js-select-mapeo').forEach(function (select) {
            select.value = '';
            select.dispatchEvent(new Event('change'));
            // El template (public/js/custom.js) envuelve todo <select> dentro de
            // .table-responsive con Bootstrap-select (selectpicker), que dibuja su
            // propio botón y no escucha el evento 'change' nativo: hay que pedirle
            // que se redibuje explícitamente o queda mostrando el texto viejo.
            if (window.jQuery && jQuery.fn.selectpicker) {
                jQuery(select).selectpicker('refresh');
            }
        });
    });

    document.getElementById('form-cancelar').addEventListener('submit', function () {
        window.AppBtn.loading('#btn-cancelar-importacion', true);
    });

    (function () {
        var form = document.getElementById('form-mapeo');
        var btnConfirmar = document.getElementById('btn-confirmar-importacion');
        var btnReanudar = document.getElementById('btn-reanudar-importacion');
        var spanFila = document.getElementById('reanudar-fila');

        // Spec 082: una tanda puede fallar por corte de red o por un 5xx (el proxy corta la
        // conexión aunque PHP haya terminado). Eso es transitorio: se reintenta con espera
        // creciente. Un 422 NO se reintenta: es un error de mapeo, determinístico, y reintentarlo
        // sólo repetiría el mismo error.
        var ESPERAS_REINTENTO = [2000, 4000, 8000];

        var mapeoFijo = null;
        var token = null;
        var modal = null;
        var barra = null;
        var detalle = null;

        function errorNoReintentable(mensaje) {
            var e = new Error(mensaje);
            e.reintentable = false;
            return e;
        }

        function pedirTanda(offset) {
            var datos = new FormData();
            mapeoFijo.forEach(function (valor, clave) { datos.append(clave, valor); });
            datos.set('_token', token);
            datos.set('offset', offset);

            return fetch(form.action, {
                method: 'POST',
                body: datos,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (respuesta) {
                if (respuesta.ok) {
                    return respuesta.json();
                }

                return respuesta.json().catch(function () { return {}; }).then(function (cuerpo) {
                    var mensaje = cuerpo.error || 'Error al importar.';
                    if (respuesta.status >= 500) {
                        throw new Error(mensaje); // transitorio: se reintenta
                    }
                    throw errorNoReintentable(mensaje);
                });
            });
        }

        function pedirTandaConReintentos(offset, intento) {
            intento = intento || 0;

            return pedirTanda(offset).catch(function (error) {
                if (error.reintentable === false || intento >= ESPERAS_REINTENTO.length) {
                    throw error;
                }

                detalle.textContent = 'Se cortó la conexión. Reintentando (' + (intento + 1) + ' de ' + ESPERAS_REINTENTO.length + ')…';

                return new Promise(function (resolver) {
                    setTimeout(resolver, ESPERAS_REINTENTO[intento]);
                }).then(function () {
                    return pedirTandaConReintentos(offset, intento + 1);
                });
            });
        }

        function procesarTanda(offset) {
            pedirTandaConReintentos(offset)
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
                    btnConfirmar.disabled = false;

                    var mensaje = error.message || 'Error al importar.';
                    if (window.toastr && window.toastr.error) {
                        window.toastr.error(mensaje);
                    } else {
                        alert(mensaje);
                    }

                    // Un error recuperable (corte) se puede retomar desde donde quedó; uno no
                    // recuperable (mapeo inválido, archivo temporal ausente) no: ahí el camino es
                    // corregir el mapeo o volver a subir el archivo.
                    if (error.reintentable === false) {
                        btnReanudar.classList.add('d-none');
                        return;
                    }

                    // +2: el offset es 0-based sobre filas de datos, y la fila del archivo cuenta
                    // el encabezado y arranca en 1 — el mismo número que muestran los errores.
                    spanFila.textContent = offset + 2;
                    btnReanudar.dataset.offset = offset;
                    btnReanudar.classList.remove('d-none');
                });
        }

        function arrancar(offset) {
            btnConfirmar.disabled = true;
            btnReanudar.classList.add('d-none');

            token = form.querySelector('input[name="_token"]').value;
            mapeoFijo = new FormData(form); // mapeo[]/personalizados[] no cambian entre tandas

            var modalEl = document.getElementById('modal-importando');
            modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            barra = document.getElementById('modal-importando-barra');
            detalle = document.getElementById('modal-importando-detalle');
            modal.show();

            procesarTanda(offset);
        }

        form.addEventListener('submit', function (evento) {
            evento.preventDefault();
            arrancar(0);
        });

        btnReanudar.addEventListener('click', function () {
            arrancar(parseInt(btnReanudar.dataset.offset || '0', 10));
        });
    })();
</script>
@endsection
