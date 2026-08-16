@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Informe de Gastos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        {{-- Bloque Desde / Hasta / Gasto Total, tal como lo encabeza Contagram. --}}
        <div class="card mb-3">
            <div class="card-body py-3">
                <div class="row align-items-center g-3">
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Desde</span>
                        <strong id="resumen-desde">&mdash;</strong>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Hasta</span>
                        <strong id="resumen-hasta">&mdash;</strong>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span class="text-muted d-block small">Gasto Total</span>
                        <h4 class="mb-0 text-primary" id="resumen-total">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group" style="width:230px">
                            <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                        </div>
                        <span id="dt-buttons-gastos"></span>
                    </div>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Categoría</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categoriasRaiz as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Subcategoría</label>
                                <select id="filtro-subcategoria" class="form-select" multiple>
                                    @foreach ($subcategorias as $s)
                                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Medio de pago</label>
                                <select id="filtro-cuenta" class="form-select" multiple>
                                    @foreach ($cuentas as $ct)
                                        <option value="{{ $ct->id }}">{{ $ct->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select" multiple>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del pago</label>
                                <select id="filtro-estado-pago" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="pagado">Pagado</option>
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
                    <table id="tabla-informe-gastos" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Categoría</th>
                                <th>Subcategoría</th>
                                <th>Descripción</th>
                                <th>Medio de pago</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeGastosConfig = {
        rutas: {
            data: @json(route('informes.gastos.data')),
            stats: @json(route('informes.gastos.stats')),
            exportar: @json(route('informes.gastos.exportar')),
            pdf: @json(route('informes.gastos.pdf')),
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informe-gastos.js'])
@endsection
