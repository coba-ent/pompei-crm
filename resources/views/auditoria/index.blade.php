@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Auditoría</h4>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center justify-content-between">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                            <i class="fas fa-filter me-1"></i> Filtros
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btn-exportar-auditoria">
                            <i class="fas fa-file-export me-1"></i> Exportar
                        </button>
                    </div>
                    <span id="dt-buttons-auditoria"></span>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-2">
                                <label class="form-label">Id</label>
                                <input type="number" id="filtro-id" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operación</label>
                                <select id="filtro-operacion" class="form-control">
                                    <option value="">Todas</option>
                                    @foreach ($operaciones as $valor => $label)
                                        <option value="{{ $valor }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-control">
                                    <option value="">Todos</option>
                                    <option value="ml">Ventas Online (Mercado Libre)</option>
                                    <option value="tn">Ventas Online (Tiendanube)</option>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha</label>
                                <input type="text" id="filtro-rango-fecha" class="form-control" autocomplete="off">
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
                    <table id="tabla-auditoria" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Fecha y Hora</th>
                                <th>Usuario</th>
                                <th>Tipo</th>
                                <th>Operación</th>
                                <th>Detalle</th>
                                <th>Total</th>
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
    window.AuditoriaConfig = {
        rutas: {
            data: "{{ route('auditoria.data') }}",
            exportar: "{{ route('auditoria.exportar') }}",
        },
        hoy: "{{ $hoy }}",
    };
</script>
@vite(['resources/js/auditoria.js'])
@endsection
