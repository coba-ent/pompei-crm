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
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        {{-- Gastos tiene un solo selector de fecha, "Emisión" (informe §3.1): un gasto
                             no maneja vencimiento de pago separado de su fecha, como sí Compras. --}}
                        <div class="input-group" style="width:190px">
                            <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión" autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                        </div>
                        <span id="dt-buttons-gastos"></span>
                    </div>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Id</label>
                                <input type="text" id="filtro-id" class="form-control" placeholder="Buscar por número de ID">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoría y/o Subcategoría</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categorias->whereNull('categoria_padre_id') as $raiz)
                                        <option value="{{ $raiz->id }}">{{ $raiz->nombre }}</option>
                                        @foreach ($categorias->where('categoria_padre_id', $raiz->id) as $hija)
                                            <option value="{{ $hija->id }}">{{ $raiz->nombre }} → {{ $hija->nombre }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Medio de pago</label>
                                <select id="filtro-medio-pago" class="form-select" multiple>
                                    @foreach ($cuentas as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Pago</label>
                                <select id="filtro-estado-pago" class="form-select" multiple>
                                    <option value="pagado">Pagado</option>
                                    <option value="pendiente">Pendiente</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Descripción</label>
                                <input type="text" id="filtro-descripcion" class="form-control" placeholder="Contiene">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select" multiple>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
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
                    <table id="tabla-gastos" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Creado</th>
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
    $datosCategorias = $categorias->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'categoria_padre_id' => $c->categoria_padre_id, 'es_sistema' => $c->es_sistema]);
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
            categoriaUpdateBase: "{{ url('categorias') }}",
        },
        categorias: @json($datosCategorias),
        cuentas: @json($datosCuentas),
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/gastos.js'])
@if (request()->boolean('crear'))
<script>
    (function () {
        var intentos = 0;
        var intervalo = setInterval(function () {
            intentos++;
            var btn = document.getElementById('btn-nuevo-gasto');
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
